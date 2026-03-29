<?php

namespace App\Models;

use Database\Factories\BorrowerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Borrower extends Model
{
    /** @use HasFactory<BorrowerFactory> */
    use HasFactory;

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
            'personal_id' => 'encrypted',
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
