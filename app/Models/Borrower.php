<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\BorrowerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Borrower extends Model
{
    /** @use HasFactory<BorrowerFactory> */
    use Auditable, HasFactory;

    /**
     * «Кредитен рейтинг» letter scale (client decision 2026-08-10:
     * «A — топ, B — много добър, C — добър; ние нямаме слаб»). Stored in
     * the legacy-named credit_score column (string since the 2026-08-10
     * migration). Value ⇒ label map for admin Selects.
     */
    public const CREDIT_RATINGS = [
        'A' => 'A — топ',
        'B' => 'B — много добър',
        'C' => 'C — добър',
    ];

    protected $fillable = [
        'full_name',
        'personal_id',
        'address',
        'phone',
        'income',
        'credit_score',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            // All PII encrypted at rest — GDPR Article 32.
            // If the database is breached, attackers get ciphertext, not personal data.
            'personal_id' => 'encrypted',
            'full_name' => 'encrypted',
            'address' => 'encrypted',
            'phone' => 'encrypted',
            'income' => 'decimal:2',
        ];
    }

    public function anonymizedProfile(): HasOne
    {
        return $this->hasOne(BorrowerAnonymizedProfile::class);
    }

    /**
     * Ensure the borrower has an investor-facing anonymized profile.
     *
     * Idempotent and called from every human creation path (the BorrowerResource
     * create page AND inline creation from the loan form) so the GDPR-safe
     * anonymized profile is never missing. NOT a model `created` hook on purpose:
     * borrower_anonymized_profiles.borrower_id is UNIQUE, and a global hook would
     * collide with factory `->has(BorrowerAnonymizedProfile::factory())` usage in
     * the test suite.
     *
     * $attributes lets the creation forms pass the REAL investor-facing values
     * (risk class, region, purpose…) — the «Неопределен» placeholders are only
     * a last-resort fallback, never something an investor should normally see
     * (boss complaint 2026-08-10).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function ensureAnonymizedProfile(array $attributes = []): void
    {
        if (! $this->anonymizedProfile()->exists()) {
            $this->anonymizedProfile()->create(array_merge([
                'risk_class' => 'C',
                'region' => 'Неопределен',
                'loan_purpose' => 'Неопределена',
            ], array_filter($attributes, fn ($value) => $value !== null && $value !== '')));
        }
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
