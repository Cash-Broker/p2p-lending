<?php

namespace App\Models;

use App\Enums\PayoutType;
use App\Traits\Auditable;
use Database\Factories\LoanOfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

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
     * Loan statuses in which an offer may still be edited. Mirrors the spirit
     * of Loan::IMMUTABLE_AFTER_DRAFT — but offers stay editable through
     * `published`/`funding` (the whole point of the feature), then lock.
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

    protected static function booted(): void
    {
        static::updating(function (LoanOffer $offer) {
            // Only the investor-facing terms are locked; touching timestamps etc.
            // is fine. Snapshots on existing investments already protect history;
            // this guard stops the LIVE offer drifting under new investors once
            // the loan is past funding (and protects API/console paths, not just
            // the status-gated Filament UI).
            if (! $offer->isDirty(['interest_rate', 'is_enabled', 'payout_type'])) {
                return;
            }

            $status = $offer->loan?->status;
            if ($status !== null && ! in_array($status, self::EDITABLE_STATUSES, true)) {
                throw new LogicException(
                    "Cannot modify offer #{$offer->id} on loan #{$offer->loan_id}: "
                    . "loan status '{$status}' no longer allows offer edits."
                );
            }
        });
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
