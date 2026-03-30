<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedIban extends Model
{
    protected $fillable = [
        'user_id',
        'iban',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'iban' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function maskedIban(): string
    {
        return str_repeat('*', max(0, strlen($this->iban) - 4)) . substr($this->iban, -4);
    }
}
