<?php

namespace App\Http\Resources;

use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanResource extends JsonResource
{
    /**
     * Investor-facing loan resource.
     * Never exposes borrower_id or raw borrower data —
     * only the anonymized profile is visible to investors.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'funded_amount' => $this->funded_amount,
            'interest_rate' => $this->interest_rate,
            'term_months' => $this->term_months,
            'type' => $this->type,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'originator' => new OriginatorResource($this->whenLoaded('originator')),
            'anonymized_profile' => new BorrowerAnonymizedProfileResource($this->whenLoaded('anonymizedProfile')),
            'funded_percentage' => bccomp($this->amount, '0', 2) > 0
                ? (int) bcmul(bcdiv($this->funded_amount, $this->amount, 4), '100')
                : 0,
            'investors_count' => $this->whenCounted('investments', $this->investments_count),
            'amortization_schedule' => AmortizationScheduleResource::collection($this->whenLoaded('amortizationSchedules')),
            // Late tracking — only meaningful when the loan is currently late.
            // For active/repaid loans these are silently null, which the
            // frontend treats as "no late state".
            'became_late_at' => $this->when($this->status === 'late', $this->became_late_at),
            // Snapshot from withMax() in PortfolioController. May be null if
            // the loan was loaded without that withMax (e.g. loan detail
            // endpoint) — frontend should treat null as "unknown".
            'days_overdue_max' => $this->max_days_late_late_only ?? null,

            // F2 — buyback context for the investor UI:
            //   is_eligible_for_buyback : whether the loan's originator has
            //                             buyback=true AND the loan is in a
            //                             status where buyback could still
            //                             apply (active/late). Used by the
            //                             detail page to render the
            //                             "Buyback налично" badge.
            //   buyback_coverage        : resolved coverage type —
            //                             originator.buyback_coverage OR
            //                             platform default. Null when the
            //                             originator has buyback=false.
            //   bought_back_at          : timestamp the loan was executed
            //                             by admin. Only surfaced on loans
            //                             in status=bought_back (terminal).
            'is_eligible_for_buyback' => $this->relationLoaded('originator')
                && $this->originator
                && $this->originator->buyback
                && in_array($this->status, ['active', 'late']),

            'buyback_coverage' => $this->when(
                $this->relationLoaded('originator') && $this->originator && $this->originator->buyback,
                fn () => $this->originator->buyback_coverage
                    ?? PlatformSetting::get('buyback_default_coverage'),
            ),

            'bought_back_at' => $this->when(
                $this->status === 'bought_back',
                $this->bought_back_at,
            ),
        ];
    }
}
