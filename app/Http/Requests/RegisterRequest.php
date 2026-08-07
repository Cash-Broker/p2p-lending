<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\ValidEik;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * For legal-entity registrations the form sends `first_name` + `last_name`
     * as separate fields (the screenshot the user signed off on splits the
     * contact person's name). The `users` table holds a single `name` column,
     * so we concatenate before validation runs — that way the rule for `name`
     * applies uniformly across both account types and downstream code keeps
     * using `$user->name` without branching.
     */
    protected function prepareForValidation(): void
    {
        // account_type is optional on the wire: when omitted (or blank) we
        // default to an individual account, matching AuthController's fallback
        // and the User model's default attribute. Keeps field-less individual
        // registration working for older clients while Rule::in still rejects
        // any explicitly-invalid value.
        if (! $this->filled('account_type')) {
            $this->merge(['account_type' => User::TYPE_INDIVIDUAL]);
        }

        if (
            $this->input('account_type') === User::TYPE_LEGAL_ENTITY
            && ! $this->filled('name')
        ) {
            $first = trim((string) $this->input('first_name', ''));
            $last = trim((string) $this->input('last_name', ''));
            $combined = trim("{$first} {$last}");
            if ($combined !== '') {
                $this->merge(['name' => $combined]);
            }
        }
    }

    public function rules(): array
    {
        $isLegalEntity = $this->input('account_type') === User::TYPE_LEGAL_ENTITY;

        $base = [
            'account_type' => ['required', 'string', Rule::in([
                User::TYPE_INDIVIDUAL,
                User::TYPE_LEGAL_ENTITY,
            ])],
            // No Unicode control characters (\n \r \0, zero-width, bidi):
            // a legal name never contains them, and the name is rendered in
            // admin-facing emails/notifications where newlines would enable
            // markdown block injection (2026-08-07 security review).
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\p{C}]+$/u'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],

            'terms_accepted' => ['required', 'accepted'],
        ];

        if (! $isLegalEntity) {
            return $base;
        }

        return array_merge($base, [
            // Contact person — split first/last in the form, combined into
            // `name` by prepareForValidation above. We still validate the
            // raw inputs so empty strings produce clear field-level errors.
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],

            // Phone — saved on users.phone so the contact channel is on the
            // user record, not the profile. AML "company_phone" stays for
            // future deeper KYC.
            'phone' => ['required', 'string', 'max:32'],

            // Company identity — the only company-level data captured at
            // registration. Everything else (address, AML declarations, UBO,
            // representative ID) is deferred to a post-registration KYC flow.
            'legal_name' => ['required', 'string', 'max:255'],
            'eik' => ['required', 'string', new ValidEik],
        ]);
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Името съдържа непозволени знаци.',

            'terms_accepted.required' => 'Трябва да приемете условията за ползване.',
            'terms_accepted.accepted' => 'Трябва да приемете условията за ползване.',

            'account_type.required' => 'Изберете тип акаунт (физическо или юридическо лице).',
            'account_type.in' => 'Невалиден тип акаунт.',

            'first_name.required' => 'Името на контактното лице е задължително.',
            'last_name.required' => 'Фамилията на контактното лице е задължителна.',
            'phone.required' => 'Телефонът е задължителен.',

            'legal_name.required' => 'Името на фирмата е задължително.',
            'eik.required' => 'ЕИК е задължителен.',
        ];
    }
}
