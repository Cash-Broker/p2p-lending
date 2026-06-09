<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentRecord extends Model
{
    public $timestamps = false;

    const TYPE_TERMS = 'terms_of_service';
    const TYPE_PRIVACY = 'privacy_policy';
    const TYPE_RISK = 'risk_disclosure';

    // Explicit consent for processing the live selfie (biometric data, GDPR
    // Art. 9(2)(a)). Captured separately at KYC submission, NOT bundled into the
    // general document re-consent gate.
    const TYPE_BIOMETRIC = 'biometric_kyc';

    // Bumped to v1.1 (2026-06-09): Terms + Privacy revised to disclose the live
    // biometric selfie (GDPR Art. 9), the front/back ID requirement, KYC file
    // erasure, and legal-entity onboarding. Risk disclosure is unchanged.
    // Existing users are re-prompted by the consent gate (EnsureConsentsCurrent)
    // which compares each user's latest accepted version against these.
    const CURRENT_TERMS_VERSION = 'v1.1';
    const CURRENT_PRIVACY_VERSION = 'v1.1';
    const CURRENT_RISK_VERSION = 'v1.0';
    const CURRENT_BIOMETRIC_VERSION = 'v1.0';

    /**
     * Document consents subject to the re-consent gate, mapped to their current
     * version. Biometric consent is intentionally excluded — it is captured at
     * the point of processing (KYC submission), not via the document gate.
     *
     * @return array<string, string>
     */
    public static function currentDocumentVersions(): array
    {
        return [
            self::TYPE_TERMS => self::CURRENT_TERMS_VERSION,
            self::TYPE_PRIVACY => self::CURRENT_PRIVACY_VERSION,
            self::TYPE_RISK => self::CURRENT_RISK_VERSION,
        ];
    }

    protected $fillable = [
        'user_id',
        'type',
        'version',
        'ip_address',
        'user_agent',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
