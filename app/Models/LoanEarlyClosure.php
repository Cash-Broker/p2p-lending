<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One early-closure event on an offer-based loan — full or partial
 * (Reni 2026-08-18).
 *
 * The borrower returned principal ahead of plan and the platform closed the
 * matching share of every investor's position. The row records what was
 * returned, at which ratio and priced to which day: the schedules it was
 * computed from are rewritten by the closure itself, so this is the only place
 * the inputs survive.
 */
class LoanEarlyClosure extends Model
{
    use Auditable;

    protected $fillable = [
        'loan_id',
        'executed_by',
        'principal_amount',
        'interest_amount',
        'ratio',
        'is_full',
        'as_of',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'ratio' => 'decimal:10',
            'is_full' => 'boolean',
            'as_of' => 'date',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    /** Principal + the day-interest paid on it. */
    public function total(): string
    {
        return bcadd((string) $this->principal_amount, (string) $this->interest_amount, 2);
    }
}
