<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single installment in an investment's payout schedule — the offer-based
 * counterpart to AmortizationSchedule (which is per-loan). One row per due
 * date per investment; generated at funded → active from the investment's
 * snapshotted rate + payout type.
 */
class InvestmentSchedule extends Model
{
    protected $fillable = [
        'investment_id',
        'loan_id',
        'due_date',
        'principal',
        'interest',
        'total',
        'status',
        'paid_at',
        'became_late_at',
        'days_late',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'principal' => 'decimal:2',
            'interest' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_at' => 'datetime',
            'became_late_at' => 'datetime',
            'days_late' => 'integer',
        ];
    }

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
