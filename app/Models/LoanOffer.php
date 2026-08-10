<?php

namespace App\Models;

use App\Enums\PayoutType;
use App\Traits\Auditable;
use Database\Factories\LoanOfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of the (up to three) investor offers on a loan: a payout structure +
 * its annual rate. The boss edits the rate / toggles availability while the
 * loan is still open for funding; once funding closes the offer locks.
 *
 * @see PayoutType for the three structures.
 */
class LoanOffer extends Model
{
    /** @use HasFactory<LoanOfferFactory> */
    use Auditable, HasFactory;

    /**
     * ⚠ NO LONGER ENFORCED — client decision 2026-08-10 (Reni): the admin
     * edits everything at any time. Committed investors are protected by
     * the rate/payout SNAPSHOT on their Investment row + the quote-vs-commit
     * guard; a live-offer edit only affects what future investors see (and
     * past funding there are no future investors anyway). Kept for docs.
     */
    const EDITABLE_STATUSES = [
        Loan::STATUS_DRAFT,
        Loan::STATUS_PUBLISHED,
        Loan::STATUS_FUNDING,
    ];

    protected $fillable = [
        'loan_id',
        'payout_type',
        'interest_rate',
        'is_enabled',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'payout_type' => PayoutType::class,
            'interest_rate' => 'decimal:2',
            'is_enabled' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** Bulgarian label of the underlying payout structure. */
    public function label(): string
    {
        return $this->payout_type->label();
    }
}
