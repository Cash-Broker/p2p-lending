<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'available',
        'invested',
        'earned',
    ];

    protected function casts(): array
    {
        return [
            // Cast to string to preserve decimal precision — avoids float rounding
            'available' => 'decimal:2',
            'invested' => 'decimal:2',
            'earned' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
