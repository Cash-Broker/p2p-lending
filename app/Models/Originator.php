<?php

namespace App\Models;

use Database\Factories\OriginatorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Originator extends Model
{
    /** @use HasFactory<OriginatorFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'website',
        'buyback',
        'buyback_coverage',
        'buyback_trigger_days',
        'logo_path',
    ];

    protected function casts(): array
    {
        return [
            'buyback' => 'boolean',
            'buyback_trigger_days' => 'integer',
        ];
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
