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

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
