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
     */
    private const PUBLIC_METADATA_KEYS = [
        'previous_became_late_at',  // recovered_from_late: when did the loan first go late
        'transitioned_to',          // recovered_from_late: target status (active or repaid)
        'days_late_at_transition',  // went_late: max days_late at the moment of transition
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
