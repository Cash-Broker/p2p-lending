<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use Auditable, HasFactory;

    // Only user_id is mass-assignable. Financial balances are NEVER mass-assignable —
    // they must only change through explicit service operations with DB locks.
    // This prevents a user from setting their own balance via a crafted API request.
    protected $fillable = [
        'user_id',
    ];

    protected $attributes = [
        'available' => '0.00',
        'reserved' => '0.00',
        'invested' => '0.00',
        'accrued' => '0.00',
        'earned' => '0.00',
        'bonus_locked' => '0.00',
    ];

    protected function casts(): array
    {
        return [
            'available' => 'decimal:2',
            'reserved' => 'decimal:2',
            'invested' => 'decimal:2',
            'accrued' => 'decimal:2',
            'earned' => 'decimal:2',
            'bonus_locked' => 'decimal:2',
        ];
    }

    /**
     * "Текущо салдо" — the current value of the investor's open positions:
     * capital still deployed (`invested`) plus profit accrued on schedule but
     * not yet released to `available` (`accrued`). Derived (not stored) so it
     * can never drift from its two source buckets.
     *
     * The three figures shown to the investor are:
     *   invested         → "Инвестирана сума"
     *   currentBalance() → "Текущо салдо"
     *   available        → "Свободни за теглене"
     *
     * `bonus_locked` is deliberately in NEITHER: a conditional bonus is not
     * spendable cash and not an open position — it is a promise the investor
     * has not yet earned (Reni 2026-08-18). It is shown as its own line.
     */
    public function currentBalance(): string
    {
        return bcadd((string) $this->invested, (string) $this->accrued, 2);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
