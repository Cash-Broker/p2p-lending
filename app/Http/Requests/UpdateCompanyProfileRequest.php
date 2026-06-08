<?php

namespace App\Http\Requests;

use App\Models\LegalEntityProfile;
use App\Rules\ValidVat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Updates the editable part of a legal-entity's company profile.
 *
 * `legal_name` and `eik` are deliberately absent: they are the registered
 * identity tied to the Commercial Register entry and the KYC record. Letting a
 * user rewrite them would silently change *which* entity the account belongs to
 * and bypass verification — the same reason `kyc_status` and `role` are guarded.
 * Corrections to those go through support / a re-KYC flow.
 */
class UpdateCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isLegalEntity() ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Normalise country to an upper-case ISO code, and make the VAT number
        // forgiving: accept a bare EIK and auto-prefix "BG" so users don't have
        // to remember the prefix. ValidVat still enforces the checksum.
        $merge = [];

        if ($this->filled('address_country')) {
            $merge['address_country'] = strtoupper(trim((string) $this->input('address_country')));
        }

        if ($this->filled('vat_number')) {
            $vat = strtoupper(preg_replace('/\s+/', '', (string) $this->input('vat_number')));
            if (ctype_digit($vat)) {
                $vat = 'BG' . $vat;
            }
            $merge['vat_number'] = $vat;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'legal_form'       => ['nullable', 'string', Rule::in(array_keys(LegalEntityProfile::LEGAL_FORMS))],
            'vat_number'       => ['nullable', 'string', new ValidVat],
            'address_country'  => ['nullable', 'string', 'size:2'],
            'address_city'     => ['nullable', 'string', 'max:120'],
            'address_postcode' => ['nullable', 'string', 'max:12'],
            'address_street'   => ['nullable', 'string', 'max:255'],
            'company_email'    => ['nullable', 'email', 'max:255'],
            'company_phone'    => ['nullable', 'string', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'legal_form.in'        => 'Невалидна правна форма.',
            'address_country.size' => 'Държавата трябва да е 2-буквен код (напр. BG).',
            'company_email.email'  => 'Невалиден имейл адрес на фирмата.',
        ];
    }
}
