<?php

namespace App\Models;

use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use HasFactory;

    protected $fillable = [
        'originator_id',
        'borrower_id',
        'amount',
        'funded_amount',
        'interest_rate',
        'interest_rate_annual',
        'term_months',
        'type',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'funded_amount' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'interest_rate_annual' => 'decimal:2',
            'published_at' => 'datetime',
        ];
    }

    public function originator(): BelongsTo
    {
        return $this->belongsTo(Originator::class);
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function anonymizedProfile(): HasOneThrough
    {
        return $this->hasOneThrough(
            BorrowerAnonymizedProfile::class,
            Borrower::class,
            'id',           // borrowers.id
            'borrower_id',  // borrower_anonymized_profiles.borrower_id
            'borrower_id',  // loans.borrower_id
            'id'            // borrowers.id
        );
    }

    public function investments(): HasMany
    {
        return $this->hasMany(Investment::class);
    }

    public function amortizationSchedules(): HasMany
    {
        return $this->hasMany(AmortizationSchedule::class);
    }

    public function isFundable(): bool
    {
        return in_array($this->status, ['published', 'funding']);
    }

    public function isFullyFunded(): bool
    {
        return bccomp($this->funded_amount, $this->amount, 2) >= 0;
    }
}
