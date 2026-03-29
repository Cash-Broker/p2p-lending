<?php

namespace App\Models;

use Database\Factories\InvestmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Investment extends Model
{
    /** @use HasFactory<InvestmentFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'loan_id',
        'amount',
        'invested_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'invested_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
