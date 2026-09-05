<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\SavedIban;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Notifications\WithdrawalApprovedNotification;
use App\Notifications\WithdrawalRejectedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WithdrawalService
{
    public function __construct(
        private WalletService $walletService,
        private FeeService $feeService,
        private TelegramService $telegram,
    ) {}

    /**
     * Threshold above which withdrawals trigger a Telegram HIGH alert.
     * In EUR. Tunable; if changed, document in CLAUDE.md.
     */
    private const TELEGRAM_BIG_WITHDRAWAL_EUR = '1000.00';

    /** SEC-01: hours after a new IBAN is confirmed before it may receive a withdrawal. */
    public const DEFAULT_NEW_IBAN_COOLDOWN_HOURS = 24;

    public static function newIbanCooldownHours(): int
    {
        return max(0, (int) PlatformSetting::get('withdrawal_new_iban_cooldown_hours', self::DEFAULT_NEW_IBAN_COOLDOWN_HOURS));
    }

    /**
     * Create a withdrawal request and RESERVE the amount.
     *
     * Moves funds from available → reserved so the user can't
     * double-spend by creating multiple withdrawal requests or
     * investing the same money while a withdrawal is pending.
     */
    public function createRequest(int $userId, string $amount, SavedIban $destination, ?string $idempotencyKey = null): WithdrawalRequest
    {
        // Audit 2026-09-01 (PAY-03): a retried POST (double click, flaky
        // network) must not reserve the money twice. Same contract as invest:
        // the key belongs to ONE account and a replay returns the first request.
        if ($idempotencyKey !== null) {
            $existing = WithdrawalRequest::where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $this->assertReplayBelongsTo($existing, $userId);

                return $existing;
            }
        }

        // With the fee switched on, an amount the fee would swallow is refused
        // NOW — not days later at approval, after the money sat reserved for
        // nothing (audit 2026-09-01).
        $quote = $this->feeService->getQuote(FeeService::CATEGORY_WITHDRAWAL, $amount);
        if ($quote->applies && bccomp(bcsub($amount, $quote->amount, 2), '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'amount' => ["The withdrawal amount must exceed the withdrawal fee of {$quote->amount} €."],
            ]);
        }

        try {
            return DB::transaction(function () use ($userId, $amount, $destination, $idempotencyKey, $quote) {
                // SEC-01 (owner 2026-09-03): the destination is re-read under lock —
                // it must belong to the requester, be confirmed by e-mail and be past
                // the cooling-off. A refusal rolls back with nothing reserved.
                $row = SavedIban::whereKey($destination->id)->where('user_id', $userId)->lockForUpdate()->first();
                $cooldown = self::newIbanCooldownHours();
                if ($row === null) {
                    throw ValidationException::withMessages(['saved_iban_id' => ['IBAN-ът не е намерен в профила ви.']]);
                }
                if (! $row->isConfirmed()) {
                    throw ValidationException::withMessages(['saved_iban_id' => ['IBAN-ът не е потвърден — отворете линка от имейла или поискайте нов от профила си.']]);
                }
                if (! $row->isWithdrawableAt(now(), $cooldown)) {
                    throw ValidationException::withMessages(['saved_iban_id' => [sprintf(
                        'Теглене към този IBAN е възможно от %s ч. (%d ч. след потвърждаването).',
                        $row->withdrawableFrom($cooldown)->timezone('Europe/Sofia')->format('d.m.Y H:i'),
                        $cooldown,
                    )]]);
                }
                $iban = $row->iban;

                // Reserve the amount — moves available → reserved
                // WalletService handles locking and validation internally
                try {
                    $this->walletService->reserve($userId, $amount);
                } catch (\InvalidArgumentException $e) {
                    throw ValidationException::withMessages([
                        'amount' => [$e->getMessage()],
                    ]);
                }

                return WithdrawalRequest::create([
                    'user_id' => $userId,
                    'amount' => $amount,
                    'iban' => $iban,
                    'saved_iban_id' => $row->id,
                    'status' => 'pending',
                    // PAY-24: what the investor saw is what will be charged.
                    'fee_quoted' => $quote->applies ? $quote->amount : '0.00',
                    'idempotency_key' => $idempotencyKey,
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two concurrent submits with the same key: the loser's reservation
            // rolled back with its transaction — hand it the winner's request.
            if ($idempotencyKey === null || ! str_contains($e->getMessage(), 'withdrawal_requests_idempotency_key_unique')) {
                throw $e;
            }

            $existing = WithdrawalRequest::where('idempotency_key', $idempotencyKey)->firstOrFail();
            $this->assertReplayBelongsTo($existing, $userId);

            return $existing;
        }
    }

    private function assertReplayBelongsTo(WithdrawalRequest $existing, int $userId): void
    {
        if ((int) $existing->user_id !== $userId) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['This idempotency key was already used by another account.'],
            ]);
        }
    }

    public function approve(int $withdrawalRequestId, int $adminId): WithdrawalRequest
    {
        $withdrawal = DB::transaction(function () use ($withdrawalRequestId, $adminId) {
            $withdrawal = WithdrawalRequest::where('id', $withdrawalRequestId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();

            // The KYC gate protects the REQUEST (route middleware); approval
            // happens hours or days later. If compliance has withdrawn the
            // verification in between — or the account was closed — the money
            // must not leave to that IBAN (audit 2026-09-01, PAY-14). Reject
            // the request instead, which releases the reservation.
            $investor = User::find($withdrawal->user_id);
            if ($investor === null || $investor->kyc_status !== 'approved') {
                throw ValidationException::withMessages([
                    'kyc' => ['Верификацията (KYC) на инвеститора вече не е одобрена — тегленето не може да бъде изплатено. Отхвърлете заявката, за да се освободят средствата.'],
                ]);
            }

            // Fee lookup happens INSIDE the transaction so a mid-approve
            // flag flip can't create a TOCTOU split between what the
            // admin previewed and what actually gets charged.
            // Owner decision 2026-09-03 (audit PAY-24): the fee CHARGED is the fee
            // DISCLOSED at request time (`fee_quoted`), whatever the flag says today.
            // Requests created before the column existed (NULL) fall back to the
            // live quote — the old behaviour, for old rows only.
            if ($withdrawal->fee_quoted !== null) {
                $feeAmount = bcadd((string) $withdrawal->fee_quoted, '0', 2);
            } else {
                $quote = $this->feeService->getQuote(FeeService::CATEGORY_WITHDRAWAL, $withdrawal->amount);
                $feeAmount = $quote->applies ? $quote->amount : '0.00';
            }
            $feeApplies = bccomp($feeAmount, '0', 2) > 0;

            $ibanSuffix = substr($withdrawal->iban, -4);
            $withdrawalRef = "withdrawal_request:{$withdrawal->id}";

            if ($feeApplies) {
                // Fee-on path. Both debits come out of the RESERVED bucket
                // (the investor set aside the full gross amount at request
                // time). Net = what wires to the investor's bank; fee =
                // what stays with the platform (off-platform).
                // Both transactions live in this single DB::transaction,
                // so partial commits are impossible.
                $net = bcsub($withdrawal->amount, $feeAmount, 2);
                if (bccomp($net, '0', 2) <= 0) {
                    throw ValidationException::withMessages([
                        'fee' => ["Withdrawal amount ({$withdrawal->amount}) must exceed fee ({$feeAmount}). Either raise the withdrawal or reject it."],
                    ]);
                }

                $this->walletService->debitReserved(
                    $withdrawal->user_id,
                    $net,
                    Transaction::TYPE_WITHDRAWAL,
                    "Withdrawal approved (IBAN: ****{$ibanSuffix}; net of {$feeAmount} € fee)",
                    $withdrawalRef,
                );

                $this->walletService->debitReserved(
                    $withdrawal->user_id,
                    $feeAmount,
                    Transaction::TYPE_FEE,
                    "Такса при теглене #{$withdrawal->id} (IBAN: ****{$ibanSuffix})",
                    "{$withdrawalRef}:fee",
                );
            } else {
                // Fee-off path — byte-identical to pre-F4 behaviour. The
                // full withdrawal amount leaves reserved as one
                // TYPE_WITHDRAWAL transaction; no TYPE_FEE row.
                $this->walletService->debitReserved(
                    $withdrawal->user_id,
                    $withdrawal->amount,
                    Transaction::TYPE_WITHDRAWAL,
                    "Withdrawal approved (IBAN: ****{$ibanSuffix})",
                    $withdrawalRef,
                );
            }

            $withdrawal->update([
                'status' => 'approved',
                'approved_by' => $adminId,
                'approved_at' => now(),
                'processed_at' => now(),
                'admin_note' => "Approved by admin #{$adminId}",
            ]);

            return $withdrawal;
        });

        try {
            $withdrawal->user->notify(new WithdrawalApprovedNotification($withdrawal->amount));
        } catch (\Throwable $e) {
            Log::warning('Failed to send withdrawal approved notification', ['withdrawal_id' => $withdrawal->id, 'error' => $e->getMessage()]);
        }

        // Telegram alert (HIGH tier) — only for big withdrawals (>= threshold).
        // Small withdrawals are routine and would generate noise.
        if (bccomp($withdrawal->amount, self::TELEGRAM_BIG_WITHDRAWAL_EUR, 2) >= 0) {
            try {
                $this->telegram->high(
                    'Голямо теглене одобрено',
                    "Теглене #{$withdrawal->id} на {$withdrawal->amount} € одобрено от admin.",
                    [
                        'withdrawal_id' => $withdrawal->id,
                        'user_id' => $withdrawal->user_id,
                        'amount_eur' => $withdrawal->amount,
                        'iban_suffix' => '****'.substr($withdrawal->iban, -4),
                        'admin_id' => $adminId,
                    ],
                );
            } catch (\Throwable $ignored) {
                // Logged inside TelegramService.
            }
        }

        return $withdrawal;
    }

    public function reject(int $withdrawalRequestId, int $adminId, ?string $note = null): WithdrawalRequest
    {
        $withdrawal = DB::transaction(function () use ($withdrawalRequestId, $adminId, $note) {
            $withdrawal = WithdrawalRequest::where('id', $withdrawalRequestId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();

            $withdrawal->update([
                'status' => 'rejected',
                'admin_note' => $note ?? "Rejected by admin #{$adminId}",
            ]);

            // Release reservation — moves reserved → available
            $this->walletService->releaseReservation(
                $withdrawal->user_id,
                $withdrawal->amount
            );

            return $withdrawal;
        });

        try {
            $withdrawal->user->notify(new WithdrawalRejectedNotification($withdrawal->amount, $note));
        } catch (\Throwable $e) {
            Log::warning('Failed to send withdrawal rejected notification', ['withdrawal_id' => $withdrawal->id, 'error' => $e->getMessage()]);
        }

        return $withdrawal;
    }
}
