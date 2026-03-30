<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentRecord extends Model
{
    public $timestamps = false;

    const TYPE_TERMS = 'terms_of_service';
    const TYPE_PRIVACY = 'privacy_policy';
    const TYPE_RISK = 'risk_disclosure';

    const CURRENT_TERMS_VERSION = 'v1.0';
    const CURRENT_PRIVACY_VERSION = 'v1.0';
    const CURRENT_RISK_VERSION = 'v1.0';

    protected $fillable = [
        'user_id',
        'type',
        'version',
        'ip_address',
        'user_agent',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
