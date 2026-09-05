<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ultimate Beneficial Owner (UDB) of a legal-entity investor.
 *
 * Stored separately from `legal_entity_profiles` because (a) one entity can
 * have multiple UBOs, (b) UBOs are natural persons and their PII has its own
 * encryption + retention contour, (c) the AML log is cleaner when each UBO
 * is its own audit-able row.
 */
class BeneficialOwner extends Model
{
    use Auditable;

    public const CONTROL_DIRECT = 'direct';

    public const CONTROL_INDIRECT = 'indirect';

    public const CONTROL_OTHER = 'other';

    public const CONTROL_TYPES = [
        self::CONTROL_DIRECT => 'Пряк',
        self::CONTROL_INDIRECT => 'Косвен',
        self::CONTROL_OTHER => 'Друг (договор / гласуване)',
    ];

    protected $fillable = [
        'legal_entity_profile_id',
        'full_name',
        'national_id',
        'date_of_birth',
        'nationality',
        'ownership_percent',
        'control_type',
        'pep_status',
        'pep_details',
    ];

    protected function casts(): array
    {
        return [
            'full_name' => 'encrypted',
            'national_id' => 'encrypted',
            'ownership_percent' => 'decimal:2',
            'pep_status' => 'boolean',
        ];
    }

    public function legalEntityProfile(): BelongsTo
    {
        return $this->belongsTo(LegalEntityProfile::class);
    }
}
