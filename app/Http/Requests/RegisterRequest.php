<?php

namespace App\Http\Requests;

use App\Models\BeneficialOwner;
use App\Models\LegalEntityProfile;
use App\Models\User;
use App\Rules\ValidEgn;
use App\Rules\ValidEik;
use App\Rules\ValidVat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isLegalEntity = $this->input('account_type') === User::TYPE_LEGAL_ENTITY;

        $base = [
            'account_type' => ['required', 'string', Rule::in([
                User::TYPE_INDIVIDUAL,
                User::TYPE_LEGAL_ENTITY,
            ])],

            // For individuals: their full name. For legal entities: the
            // representative's name (the person filling the form / logging in).
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],

            'terms_accepted' => ['required', 'accepted'],
        ];

        if (! $isLegalEntity) {
            return $base;
        }

        return array_merge($base, [
            // ── Company identity ─────────────────────────────────────────────
            'legal_name'  => ['required', 'string', 'max:255'],
            'legal_form'  => ['required', Rule::in(array_keys(LegalEntityProfile::LEGAL_FORMS))],
            'eik'         => ['required', 'string', new ValidEik],
            'vat_number'  => ['nullable', 'string', new ValidVat],

            // ── Address ──────────────────────────────────────────────────────
            'address_country'  => ['required', 'string', 'size:2'],
            'address_city'     => ['required', 'string', 'max:120'],
            'address_postcode' => ['required', 'string', 'max:12'],
            'address_street'   => ['required', 'string', 'max:255'],

            // ── Company contact ──────────────────────────────────────────────
            'company_email' => ['required', 'email', 'max:255'],
            'company_phone' => ['required', 'string', 'max:32'],

            // ── Representative ───────────────────────────────────────────────
            'representative_role' => ['required', Rule::in(array_keys(LegalEntityProfile::REPRESENTATIVE_ROLES))],
            // ЕГН OR DOB — exactly one is required.
            'representative_egn' => ['nullable', 'required_without:representative_dob', new ValidEgn],
            'representative_dob' => ['nullable', 'required_without:representative_egn', 'date', 'before:today'],

            // ── AML — PEP ────────────────────────────────────────────────────
            'pep_status'  => ['required', 'boolean'],
            'pep_details' => ['nullable', 'required_if:pep_status,true', 'string', 'max:1000'],

            // ── AML — Source of funds ────────────────────────────────────────
            'source_of_funds'       => ['required', Rule::in(array_keys(LegalEntityProfile::SOURCES_OF_FUNDS))],
            'source_of_funds_other' => ['nullable', 'required_if:source_of_funds,other', 'string', 'max:500'],

            // ── Beneficial owners (UDB) ──────────────────────────────────────
            // ZMIP чл. 59 — at least one UBO is required for any legal entity.
            'beneficial_owners'                       => ['required', 'array', 'min:1', 'max:20'],
            'beneficial_owners.*.full_name'           => ['required', 'string', 'max:255'],
            'beneficial_owners.*.national_id'         => ['nullable', 'required_without:beneficial_owners.*.date_of_birth'],
            'beneficial_owners.*.date_of_birth'       => ['nullable', 'required_without:beneficial_owners.*.national_id', 'date', 'before:today'],
            'beneficial_owners.*.nationality'         => ['required', 'string', 'size:2'],
            'beneficial_owners.*.ownership_percent'   => ['required', 'numeric', 'min:0.01', 'max:100'],
            'beneficial_owners.*.control_type'        => ['required', Rule::in(array_keys(BeneficialOwner::CONTROL_TYPES))],
            'beneficial_owners.*.pep_status'          => ['required', 'boolean'],
            'beneficial_owners.*.pep_details'         => ['nullable', 'string', 'max:1000'],
        ]);
    }

    public function messages(): array
    {
        return [
            'terms_accepted.required' => 'Трябва да приемете условията за ползване.',
            'terms_accepted.accepted' => 'Трябва да приемете условията за ползване.',

            'account_type.required'   => 'Изберете тип акаунт (физическо или юридическо лице).',
            'account_type.in'         => 'Невалиден тип акаунт.',

            'legal_name.required'     => 'Юридическото наименование е задължително.',
            'legal_form.required'     => 'Изберете правна форма.',
            'eik.required'            => 'ЕИК е задължителен.',

            'address_country.required'  => 'Държавата е задължителна.',
            'address_city.required'     => 'Градът е задължителен.',
            'address_postcode.required' => 'Пощенският код е задължителен.',
            'address_street.required'   => 'Улицата е задължителна.',

            'company_email.required' => 'Имейлът на фирмата е задължителен.',
            'company_email.email'    => 'Имейлът на фирмата е невалиден.',
            'company_phone.required' => 'Телефонът на фирмата е задължителен.',

            'representative_role.required'    => 'Изберете качество на представляващия.',
            'representative_egn.required_without' => 'Въведете ЕГН или дата на раждане.',
            'representative_dob.required_without' => 'Въведете ЕГН или дата на раждане.',

            'pep_status.required'  => 'Декларирайте PEP статус.',
            'pep_details.required_if' => 'При PEP статус "Да" посочете подробности.',

            'source_of_funds.required'        => 'Изберете източник на средствата.',
            'source_of_funds_other.required_if' => 'При източник "Друго" въведете описание.',

            'beneficial_owners.required'      => 'Поне един действителен собственик (УДБ) е задължителен.',
            'beneficial_owners.min'           => 'Поне един действителен собственик (УДБ) е задължителен.',
            'beneficial_owners.*.full_name.required' => 'Името на УДБ е задължително.',
            'beneficial_owners.*.ownership_percent.required' => 'Процентът собственост е задължителен.',
            'beneficial_owners.*.ownership_percent.min'      => 'Процентът собственост трябва да е положителен.',
            'beneficial_owners.*.ownership_percent.max'      => 'Процентът собственост не може да надвишава 100.',
            'beneficial_owners.*.control_type.required'      => 'Изберете тип контрол.',
        ];
    }
}
