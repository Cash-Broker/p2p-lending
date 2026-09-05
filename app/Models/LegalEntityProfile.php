<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Company-side profile for a legal-entity investor (1:1 with `users`).
 *
 * Registration captures only `legal_name` + `eik` (per the simplified onboarding
 * UX). The remaining columns — address, representative ID, PEP status, source
 * of funds — are populated later in a post-registration KYC workflow and the
 * 2026_05_04_130000_relax_legal_entity_profile_columns migration made them
 * nullable on the table to allow the deferred fill-in.
 *
 * `eik`, `vat_number`, `representative_egn` are encrypted at rest. The constant
 * arrays below remain so the deferred KYC form can offer the same canonical
 * dropdown options.
 */
class LegalEntityProfile extends Model
{
    use Auditable;

    public const LEGAL_FORMS = [
        'EOOD' => 'ЕООД',
        'OOD' => 'ООД',
        'AD' => 'АД',
        'EAD' => 'ЕАД',
        'ADSITZ' => 'АДСИЦ',
        'ET' => 'ЕТ',
        'KOOPERATSIYA' => 'Кооперация',
        'DRUGO' => 'Друго',
    ];

    public const REPRESENTATIVE_ROLES = [
        'upravitel' => 'Управител',
        'prokurist' => 'Прокурист',
        'upalnomoshteno' => 'Упълномощено лице',
    ];

    public const SOURCES_OF_FUNDS = [
        'business_income' => 'Доходи от стопанска дейност',
        'dividends' => 'Дивиденти',
        'asset_sale' => 'Продажба на актив',
        'loan' => 'Заем',
        'other' => 'Друго',
    ];

    protected $fillable = [
        'user_id',
        'legal_name',
        'legal_form',
        'eik',
        'vat_number',
        'address_country',
        'address_city',
        'address_postcode',
        'address_street',
        'company_email',
        'company_phone',
        'representative_role',
        'representative_egn',
        'representative_dob',
        'pep_status',
        'pep_details',
        'source_of_funds',
        'source_of_funds_other',
    ];

    protected function casts(): array
    {
        return [
            'eik' => 'encrypted',
            'vat_number' => 'encrypted',
            'representative_egn' => 'encrypted',
            'pep_status' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function beneficialOwners(): HasMany
    {
        return $this->hasMany(BeneficialOwner::class);
    }
}
