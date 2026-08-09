<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use Auditable, HasFactory;

    // Transactions are immutable — no updated_at
    const UPDATED_AT = null;

    // Transaction types — used across services, controllers, and tests
    const TYPE_DEPOSIT = 'deposit';

    const TYPE_WITHDRAWAL = 'withdrawal';

    const TYPE_INVESTMENT = 'investment';

    const TYPE_REPAYMENT_PRINCIPAL = 'repayment_principal';

    const TYPE_REPAYMENT_INTEREST = 'repayment_interest';

    const TYPE_FEE = 'fee';

    // F2 — buyback distribution types. Separate from TYPE_REPAYMENT_* so
    // reconciliation and per-investor reporting can distinguish repayments
    // (borrower paid) from buybacks (originator honoured guarantee).
    const TYPE_BUYBACK_PRINCIPAL = 'buyback_principal';

    const TYPE_BUYBACK_INTEREST = 'buyback_interest';

    // F3 — early repayment distribution types. Separate from TYPE_REPAYMENT_*
    // so reconciliation can distinguish full-close payoffs (borrower paid
    // early) from scheduled monthly repayments, AND from TYPE_BUYBACK_*
    // (originator paid on behalf of borrower).
    const TYPE_EARLY_REPAYMENT_PRINCIPAL = 'early_repayment_principal';

    const TYPE_EARLY_REPAYMENT_INTEREST = 'early_repayment_interest';

    // Scheduled-accrual payout feature (boss 2026-06-23).
    //   INTEREST_ACCRUED  — profit recognised on schedule but LOCKED in the
    //                       `accrued` bucket (текущо салдо grows). No cash move.
    //   INTEREST_RELEASED — locked profit moved into spendable `available`
    //                       (+ earned counter). The accrued→available release.
    const TYPE_INTEREST_ACCRUED = 'interest_accrued';

    const TYPE_INTEREST_RELEASED = 'interest_released';

    //   INTEREST_ACCRUAL_REVERSED — write-off of previously-accrued locked
    //                       profit that will never be funded (e.g. a
    //                       principal-only buyback: the originator covers no
    //                       interest). Decrements `accrued` only — no cash
    //                       move, no earned income.
    const TYPE_INTEREST_ACCRUAL_REVERSED = 'interest_accrual_reversed';

    // Admin-granted promotional credit (boss 2026-08-09: «код БОНУС» —
    // e.g. 100-200 € for bringing in a client). Cash-in WITHOUT a bank
    // wire behind it: unlike TYPE_DEPOSIT it must NOT reconcile against
    // the bank statement — SUM(type='bonus') is platform marketing spend
    // the company owes the virtual ledger (mirror of the TYPE_FEE note).
    const TYPE_BONUS = 'bonus';

    const TYPES = [
        self::TYPE_DEPOSIT,
        self::TYPE_WITHDRAWAL,
        self::TYPE_INVESTMENT,
        self::TYPE_REPAYMENT_PRINCIPAL,
        self::TYPE_REPAYMENT_INTEREST,
        self::TYPE_BUYBACK_PRINCIPAL,
        self::TYPE_BUYBACK_INTEREST,
        self::TYPE_EARLY_REPAYMENT_PRINCIPAL,
        self::TYPE_EARLY_REPAYMENT_INTEREST,
        self::TYPE_INTEREST_ACCRUED,
        self::TYPE_INTEREST_RELEASED,
        self::TYPE_INTEREST_ACCRUAL_REVERSED,
        self::TYPE_FEE,
        self::TYPE_BONUS,
    ];

    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'description',
        'reference',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
