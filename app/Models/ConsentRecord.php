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

    // Bumped to v1.1 (2026-06-09): Terms + Privacy revised to disclose the live
    // biometric selfie (GDPR Art. 9), the front/back ID requirement, KYC file
    // erasure, and legal-entity onboarding. Risk disclosure is unchanged.
    // NOTE: existing users are NOT re-prompted yet — registration records
    // consent once and nothing compares stored vs. current version. A
    // re-consent gate is required before this is lawfully effective for
    // already-registered users (esp. the Art. 9 biometric consent).
    const CURRENT_TERMS_VERSION = 'v1.1';
    const CURRENT_PRIVACY_VERSION = 'v1.1';
    const CURRENT_RISK_VERSION = 'v1.0';

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
