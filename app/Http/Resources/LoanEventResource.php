<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Investor-facing serialisation of a loan_events row.
 *
 * Anonymised: NEVER exposes triggered_by_user_id (admin user id) or
 * raw operator-internal metadata. Investors see WHAT happened and
 * WHEN, but not WHICH internal actor pressed the button.
 *
 * Metadata sanitisation uses a strict WHITELIST (per Step 5 spec
 * decision): only the keys in $publicMetadataKeys are returned.
 * Any future F2/F3/F4 metadata key is silently dropped until it is
 * explicitly added here. Defaults to opt-in safety so a careless
 * `metadata['internal_admin_note' => '...']` write in some future
 * service can never leak to investors.
 */
class LoanEventResource extends JsonResource
{
    /**
     * Whitelist of metadata keys safe to expose to investors.
     *
     * Add a new key here ONLY after auditing it for PII / internal-state
     * leakage. Never widen with `*` or remove the whitelist.
     *
     * DELIBERATELY NOT EXPOSED (stay admin-only):
     *   - triggered_by_user_id        (admin identity)
     *   - executed_by_admin_id        (admin identity — F2 buyback_completed)
     *   - late_schedule_count / paid_schedule_count / total_schedule_count
     *                                 (operations-internal counters)
     *   - originator_id               (redundant with Loan.originator relation;
     *                                  metadata doesn't need to duplicate)
     */
    private const PUBLIC_METADATA_KEYS = [
        // F1 — went_late
        'days_late_at_transition',
        // F1 — recovered_from_late
        'previous_became_late_at',
        'transitioned_to',

        // F2 — buyback_triggered (cron detection snapshot)
        'eligible_at',
        'days_since_became_late',
        'calculated_buyback_amount_at_detection',
        'coverage_type',

        // F2 — buyback_completed (execution aggregates)
        'total_amount',
        'total_principal',
        'total_interest',
        'investor_count',
        'executed_at',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            // 'system' or 'admin' — investors see WHO source, never WHICH user.
            'triggered_by' => $this->triggered_by,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'metadata' => $this->sanitizeMetadata($this->metadata ?? []),
        ];
    }

    private function sanitizeMetadata(array $metadata): array
    {
        // Whitelist intersection — opt-in safety.
        return array_intersect_key($metadata, array_flip(self::PUBLIC_METADATA_KEYS));
    }
}
