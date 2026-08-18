<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\BonusGrant;
use App\Models\Investment;
use App\Models\LoanPromotion;
use App\Models\User;
use App\Notifications\BonusReleasedNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Conditional bonuses (Reni 2026-08-18).
 *
 * A bonus is granted LOCKED and becomes spendable only when the investor has
 * money genuinely working in the platform:
 *
 *   Σ (investments made after the grant, on which the investor has already
 *      received {required_installments} scheduled payments) ≥ base_amount
 *
 * Reni's decisions this encodes:
 *   • the amount may be spread over SEVERAL loans — the sum is what counts;
 *   • «след третия падеж» — three received payouts, not three calendar months;
 *   • capitalized plans pay once, at maturity: an investment whose plan has
 *     fewer rows than required unlocks on its LAST one instead;
 *   • no claim button — the moment the condition holds, the money moves.
 *
 * Only investments made from `qualifies_from` onward count: the bonus is meant
 * to bring NEW money in, not to reward a position the investor already held.
 * For promo bonuses that timestamp is the investment's own, so the investment
 * that earned the bonus is the one that unlocks it.
 *
 * The condition is monotone on purpose — it looks at payouts already received,
 * so once true it stays true. Released money is never re-locked.
 */
class BonusService
{
    public function __construct(private WalletService $walletService) {}

    /**
     * Admin «Начисли бонус»: credit the locked bucket and record the terms.
     * Caller is responsible for authorization and the duplicate-grant guard.
     */
    public function grantAdminBonus(
        User $user,
        string $amount,
        string $baseAmount,
        string $reason,
        int $adminId,
        string $reference,
    ): BonusGrant {
        if (bccomp($baseAmount, $amount, 2) < 0) {
            throw new InvalidArgumentException('Bonus base amount must be at least the bonus itself.');
        }

        return DB::transaction(function () use ($user, $amount, $baseAmount, $reason, $adminId, $reference) {
            $transaction = $this->walletService->bonusLocked(
                $user->id,
                $amount,
                'Бонус: '.$reason,
                $reference,
            );

            return BonusGrant::create([
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'base_amount' => $baseAmount,
                'required_installments' => BonusGrant::DEFAULT_REQUIRED_INSTALLMENTS,
                'source' => BonusGrant::SOURCE_ADMIN,
                'granted_by' => $adminId,
                'reason' => $reason,
                'status' => BonusGrant::STATUS_LOCKED,
                // Only money that arrives from now on can unlock it.
                'qualifies_from' => now(),
            ]);
        });
    }

    /**
     * Flash-promo upfront bonus. Runs INSIDE the invest transaction, so it
     * must not open one of its own beyond the wallet move.
     *
     * The base is the investment that earned the bonus and `qualifies_from`
     * is that investment's timestamp — the investor does not have to invest a
     * second time, but the money must stay long enough to serve its
     * installments before the bonus is cashable.
     */
    public function grantPromoBonus(
        User $user,
        Investment $investment,
        LoanPromotion $promotion,
        string $amount,
        string $description,
        string $reference,
    ): BonusGrant {
        $transaction = $this->walletService->bonusLocked($user->id, $amount, $description, $reference);

        return BonusGrant::create([
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'amount' => $amount,
            'base_amount' => (string) $investment->amount,
            'required_installments' => BonusGrant::DEFAULT_REQUIRED_INSTALLMENTS,
            'source' => BonusGrant::SOURCE_PROMO,
            'loan_promotion_id' => $promotion->id,
            'investment_id' => $investment->id,
            'reason' => "Промо оферта — кредит #{$investment->loan_id}",
            'status' => BonusGrant::STATUS_LOCKED,
            'qualifies_from' => $investment->invested_at,
        ]);
    }

    /**
     * Release every locked grant of one investor whose condition now holds.
     *
     * @return array<int, BonusGrant> the grants released by this call
     */
    public function evaluateUser(int $userId): array
    {
        $grants = BonusGrant::where('user_id', $userId)
            ->locked()
            ->orderBy('id')
            ->get();

        $released = [];

        foreach ($grants as $grant) {
            $qualified = $this->qualifiedInvestedAmount(
                $userId,
                $grant->qualifies_from,
                $grant->required_installments,
            );

            if (bccomp($qualified, (string) $grant->base_amount, 2) < 0) {
                continue;
            }

            $releasedGrant = $this->release($grant);

            if ($releasedGrant !== null) {
                $released[] = $releasedGrant;
            }
        }

        return $released;
    }

    /**
     * Σ of the investor's investments that already carry the required number
     * of RECEIVED installments.
     *
     * Investments are counted whether or not they are still open: an investor
     * who kept 5000 € working for three payouts has met the condition, and a
     * loan that finished meanwhile does not undo that.
     */
    public function qualifiedInvestedAmount(int $userId, CarbonInterface $since, int $requiredInstallments): string
    {
        $investments = Investment::where('user_id', $userId)
            ->where('invested_at', '>=', $since)
            ->withCount([
                'schedules as installments_total',
                'schedules as installments_paid' => fn ($query) => $query->where('status', 'paid'),
            ])
            ->get();

        $total = '0.00';

        foreach ($investments as $investment) {
            if ($this->hasServedInstallments($investment, $requiredInstallments)) {
                $total = bcadd($total, (string) $investment->amount, 2);
            }
        }

        return $total;
    }

    /**
     * Has this investment paid out often enough to count?
     *
     * Offer investments own their `investment_schedules`; legacy investments
     * (loan_offer_id null) share the loan's `amortization_schedules`, so the
     * count comes from there. A plan with fewer rows than required — the
     * capitalized single maturity row — needs all of them.
     */
    private function hasServedInstallments(Investment $investment, int $requiredInstallments): bool
    {
        $total = (int) ($investment->installments_total ?? 0);
        $paid = (int) ($investment->installments_paid ?? 0);

        if ($total === 0) {
            $total = AmortizationSchedule::where('loan_id', $investment->loan_id)->count();
            $paid = AmortizationSchedule::where('loan_id', $investment->loan_id)
                ->where('status', 'paid')
                ->count();
        }

        // No schedule at all yet — the loan has not been activated. Nothing
        // has been served, so the investment cannot qualify.
        if ($total === 0) {
            return false;
        }

        return $paid >= min($requiredInstallments, $total);
    }

    /**
     * Move one grant's money into `available`. Idempotent: the row is locked
     * and re-checked, so a cron racing an admin cannot pay the bonus twice.
     */
    public function release(BonusGrant $grant): ?BonusGrant
    {
        $released = DB::transaction(function () use ($grant) {
            $fresh = BonusGrant::whereKey($grant->id)->lockForUpdate()->first();

            if ($fresh === null || ! $fresh->isLocked()) {
                return null;
            }

            $transaction = $this->walletService->releaseBonus(
                $fresh->user_id,
                (string) $fresh->amount,
                'Освободен бонус: '.($fresh->reason ?? 'изпълнено условие'),
                "bonus_grant:{$fresh->id}:release",
            );

            $fresh->forceFill([
                'status' => BonusGrant::STATUS_RELEASED,
                'released_at' => now(),
                'release_transaction_id' => $transaction->id,
            ])->save();

            return $fresh;
        });

        if ($released === null) {
            return null;
        }

        // Money first, notification after commit — a failed mail must never
        // roll the release back.
        try {
            $released->user?->notify(new BonusReleasedNotification(
                (string) $released->amount,
                $released->reason ?? '',
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to send bonus released notification', [
                'bonus_grant_id' => $released->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $released;
    }

    /**
     * Write a locked bonus off. Used by the admin action and by account
     * closure — a conditional bonus the investor never earned must not
     * silently vanish with the wallet row.
     */
    public function cancel(BonusGrant $grant, ?int $adminId, string $reason): ?BonusGrant
    {
        return DB::transaction(function () use ($grant, $adminId, $reason) {
            $fresh = BonusGrant::whereKey($grant->id)->lockForUpdate()->first();

            if ($fresh === null || ! $fresh->isLocked()) {
                return null;
            }

            $this->walletService->cancelLockedBonus(
                $fresh->user_id,
                (string) $fresh->amount,
                'Отменен бонус: '.$reason,
                "bonus_grant:{$fresh->id}:cancel",
            );

            $fresh->forceFill([
                'status' => BonusGrant::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $adminId,
                'cancel_reason' => $reason,
            ])->save();

            return $fresh;
        });
    }

    /**
     * What the investor still has to do, for the dashboard card.
     *
     * @return array{amount:string, base_amount:string, qualified_amount:string, remaining_amount:string, required_installments:int}|null
     */
    public function lockedSummary(int $userId): ?array
    {
        $grants = BonusGrant::where('user_id', $userId)->locked()->get();

        if ($grants->isEmpty()) {
            return null;
        }

        $amount = '0.00';
        $base = '0.00';
        $qualified = '0.00';
        $required = BonusGrant::DEFAULT_REQUIRED_INSTALLMENTS;

        foreach ($grants as $grant) {
            $amount = bcadd($amount, (string) $grant->amount, 2);
            $base = bcadd($base, (string) $grant->base_amount, 2);
            $qualified = bcadd($qualified, $this->qualifiedInvestedAmount(
                $userId,
                $grant->qualifies_from,
                $grant->required_installments,
            ), 2);
            $required = $grant->required_installments;
        }

        $remaining = bcsub($base, $qualified, 2);

        return [
            'amount' => $amount,
            'base_amount' => $base,
            'qualified_amount' => $qualified,
            'remaining_amount' => bccomp($remaining, '0', 2) > 0 ? $remaining : '0.00',
            'required_installments' => $required,
        ];
    }
}
