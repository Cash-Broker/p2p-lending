<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory, Auditable;

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

    const TYPES = [
        self::TYPE_DEPOSIT,
        self::TYPE_WITHDRAWAL,
        self::TYPE_INVESTMENT,
        self::TYPE_REPAYMENT_PRINCIPAL,
        self::TYPE_REPAYMENT_INTEREST,
        self::TYPE_BUYBACK_PRINCIPAL,
        self::TYPE_BUYBACK_INTEREST,
        self::TYPE_FEE,
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
