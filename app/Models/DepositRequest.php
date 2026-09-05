<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\DepositRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DepositRequest extends Model
{
    /** @use HasFactory<DepositRequestFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'user_id',
        'amount',
        'reference_code',
        'bank_reference',
        'status',
        'admin_note',
        'approved_by',
        'confirmed_at',
        'expires_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Auto-generate the code on first save — it's the user-facing
        // contract (what the user pastes in the bank wire reference).
        // Generating it here keeps every creation path (controller,
        // service, factory) consistent.
        //
        // No expires_at: a pending code stays valid until admin
        // approves/rejects a deposit against it. The user may have wired
        // money days ago — a code that dies on a timer strands that
        // transfer (client decision 2026-07-17). The column survives for
        // historical rows only.
        static::creating(function (DepositRequest $request) {
            if (empty($request->reference_code)) {
                $request->reference_code = 'DEP-'.strtoupper(Str::random(8));
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
