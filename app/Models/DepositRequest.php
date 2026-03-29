<?php

namespace App\Models;

use Database\Factories\DepositRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DepositRequest extends Model
{
    /** @use HasFactory<DepositRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'amount',
        'reference_code',
        'status',
        'admin_note',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Generate unique reference code on creation so the investor
        // can include it in their bank transfer for easy matching
        static::creating(function (DepositRequest $request) {
            if (empty($request->reference_code)) {
                $request->reference_code = 'DEP-' . strtoupper(Str::random(8));
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
