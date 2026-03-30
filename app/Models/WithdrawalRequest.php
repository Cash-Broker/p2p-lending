<?php

namespace App\Models;

use Database\Factories\WithdrawalRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WithdrawalRequest extends Model
{
    /** @use HasFactory<WithdrawalRequestFactory> */
    use HasFactory, \App\Traits\Auditable;

    protected $fillable = [
        'user_id',
        'amount',
        'iban',
        'status',
        'admin_note',
        'processed_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'iban' => 'encrypted', // Bank account number encrypted at rest — GDPR
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Show only last 4 chars of IBAN in API responses — PCI-like data minimization
    public function maskedIban(): string
    {
        return str_repeat('*', max(0, strlen($this->iban) - 4)) . substr($this->iban, -4);
    }
}
