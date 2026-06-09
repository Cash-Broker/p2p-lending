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
    use HasFactory, Auditable;

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
     */
    public function ensureAnonymizedProfile(): void
    {
        if (! $this->anonymizedProfile()->exists()) {
            $this->anonymizedProfile()->create([
                'risk_class' => 'C',
                'region' => 'Неопределен',
                'loan_purpose' => 'Неопределена',
            ]);
        }
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
