<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'invested_at' => $this->invested_at,
            // Chosen-offer snapshot (null for legacy investments).
            'loan_offer_id' => $this->loan_offer_id,
            'interest_rate' => $this->interest_rate,
            'payout_type' => $this->payout_type?->value,
            'payout_label' => $this->payout_type?->label(),
            // Present only when the query added withExists('contract') —
            // gates the «Договор» PDF link (contracts exist from the
            // feature's introduction onward, older investments have none).
            'has_contract' => $this->when(
                array_key_exists('contract_exists', $this->resource->getAttributes()),
                fn () => (bool) $this->contract_exists,
            ),
            'loan' => new LoanResource($this->whenLoaded('loan')),
            // Per-installment breakdown the investor must always be able to see:
            // each row's principal / interest / total per their plan (boss req).
            // Offer-based investments only; empty for legacy (pro-rata) positions.
            'schedule' => $this->whenLoaded('schedules', function () {
                // PAY-13: while the loan's payouts are paused, due rows stay `pending`
                // in the DB — «задържана» is derived here so rows falling due DURING
                // the pause render correctly too.
                $paused = $this->relationLoaded('loan') && $this->loan !== null && $this->loan->isPayoutPaused();
                $today = now()->toDateString();

                return $this->schedules
                    ->sortBy('due_date')
                    ->values()
                    ->map(fn ($row) => [
                        'due_date' => $row->due_date?->toDateString(),
                        'principal' => $row->principal,
                        'interest' => $row->interest,
                        'total' => $row->total,
                        'status' => $row->status,
                        'withheld' => $paused && $row->status === 'pending' && $row->due_date !== null && $row->due_date->toDateString() <= $today,
                    ]);
            }),
        ];
    }
}
