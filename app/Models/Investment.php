<?php

namespace App\Models;

use App\Enums\PayoutType;
use Database\Factories\InvestmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Investment extends Model
{
    /** @use HasFactory<InvestmentFactory> */
    use \App\Traits\Auditable, HasFactory;

    protected $fillable = [
        'user_id',
        'loan_id',
        'loan_offer_id',
        'amount',
        // Snapshots of the chosen offer, frozen at invest time. Source of truth
        // for this investor's cash flow; immune to later offer edits. Null for
        // legacy investments made before the 3-offer feature.
        'interest_rate',
        'payout_type',
        'invested_at',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'payout_type' => PayoutType::class,
            'invested_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function loanOffer(): BelongsTo
    {
        return $this->belongsTo(LoanOffer::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(InvestmentSchedule::class);
    }

    /**
     * The concluded loan agreement snapshot + click-wrap acceptance
     * evidence. Present for every offer-based investment created after the
     * contract feature shipped; null for older/legacy investments.
     */
    public function contract(): HasOne
    {
        return $this->hasOne(InvestmentContract::class);
    }

    /** Whether this investment was made under a 3-offer payout structure. */
    public function usesOffer(): bool
    {
        return $this->loan_offer_id !== null;
    }
}
