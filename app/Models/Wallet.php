<?php

namespace App\Models;

use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory, Auditable;

    // Only user_id is mass-assignable. Financial balances are NEVER mass-assignable —
    // they must only change through explicit service operations with DB locks.
    // This prevents a user from setting their own balance via a crafted API request.
    protected $fillable = [
        'user_id',
    ];

    protected $attributes = [
        'available' => '0.00',
        'invested' => '0.00',
        'earned' => '0.00',
    ];

    protected function casts(): array
    {
        return [
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
