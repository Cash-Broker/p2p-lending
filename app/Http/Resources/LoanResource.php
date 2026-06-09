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
            // How much of the loan is offered to investors (== amount when no cap).
            'investable_amount' => $this->investableAmount(),
            'funded_amount' => $this->funded_amount,
            'interest_rate' => $this->interest_rate,
            // F5 — Annual Percentage Rate (Годишен Процент на Разходите).
            // Borrower-facing cost-of-credit metric. Null-safe: returns null
            // when interest_rate_annual is unset / non-positive, which the
            // SPA renders as "—". See DECISIONS.md F5-01.
            'apr' => $this->apr(),
            'term_months' => $this->term_months,
            'type' => $this->type,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'originator' => new OriginatorResource($this->whenLoaded('originator')),
            'anonymized_profile' => new BorrowerAnonymizedProfileResource($this->whenLoaded('anonymizedProfile')),
            // Co-debtor (съдлъжник) anonymized profile — null when the loan has
            // no co-debtor. Real co-debtor PII is never exposed.
            'co_borrower_anonymized_profile' => $this->relationLoaded('coBorrowerAnonymizedProfile') && $this->coBorrowerAnonymizedProfile
                ? new BorrowerAnonymizedProfileResource($this->coBorrowerAnonymizedProfile)
                : null,
            // Progress against the investable cap so the bar reaches 100% at the
            // cap, not at the (possibly larger) nominal amount.
            'funded_percentage' => bccomp($this->investableAmount(), '0', 2) > 0
                ? (int) bcmul(bcdiv($this->funded_amount, $this->investableAmount(), 4), '100')
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
