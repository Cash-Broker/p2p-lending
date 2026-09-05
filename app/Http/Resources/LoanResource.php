<?php

namespace App\Http\Resources;

use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

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
            // 3-offer feature — the (enabled) investor offers + their rate range.
            // Eager-loaded already filtered to is_enabled in the controller.
            // Legacy `interest_rate` above is kept so existing consumers don't break.
            'offers' => LoanOfferResource::collection($this->whenLoaded('offers')),
            'offer_rate_range' => $this->relationLoaded('offers') ? $this->offerRateRange() : null,
            // F5 — Annual Percentage Rate (Годишен Процент на Разходите).
            // Borrower-facing cost-of-credit metric. Null-safe: returns null
            // when interest_rate_annual is unset / non-positive, which the
            // SPA renders as "—". See DECISIONS.md F5-01.
            'apr' => $this->apr(),
            'term_months' => $this->term_months,
            'type' => $this->type,
            'status' => $this->status,
            // 'public' | 'private' — lets the SPA badge a link-only loan.
            'visibility' => $this->visibility,
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
            // Social proof (2026-08-14): anonymous timestamp of the newest
            // investment — «последна инвестиция преди X мин» on the invest page.
            // ISO-8601 with offset ON PURPOSE: the loadMax aggregate carries no
            // datetime cast, and a raw «Y-m-d H:i:s» string parses as VIEWER-
            // local time in browsers (hours of skew) or Invalid Date on older
            // Safari.
            'last_invested_at' => $this->when(
                array_key_exists('investments_max_invested_at', $this->getAttributes()),
                fn () => $this->investments_max_invested_at !== null
                    ? Carbon::parse($this->investments_max_invested_at)->toIso8601String()
                    : null,
            ),
            'amortization_schedule' => AmortizationScheduleResource::collection($this->whenLoaded('amortizationSchedules')),
            // Late tracking — only meaningful when the loan is currently late.
            // For active/repaid loans these are silently null, which the
            // frontend treats as "no late state".
            'became_late_at' => $this->when($this->status === 'late', $this->became_late_at),
            // Snapshot from withMax() in PortfolioController. May be null if
            // the loan was loaded without that withMax (e.g. loan detail
            // endpoint) — frontend should treat null as "unknown".
            // For OFFER loans this is the BORROWER's tracker delay (PAY-13) — the
            // investor's own rows may be fully paid.
            'days_overdue_max' => $this->max_days_late_late_only ?? null,

            // PAY-13 — investor-facing pause flag (stamp AND setting). No threshold exposed.
            'payouts_paused' => $this->isPayoutPaused(),
            'payouts_paused_at' => $this->when($this->isPayoutPaused(), $this->payouts_paused_at),

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

            // PAY-30: «приключен без пълно финансиране» is visible to holders.
            'closed_from_status' => $this->when($this->status === 'repaid', $this->closed_from_status),
            'closed_at' => $this->when($this->status === 'repaid', $this->closed_at),
        ];
    }
}
