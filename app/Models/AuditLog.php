<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    const UPDATED_AT = null; // Audit logs are immutable — never modified

    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Audit 2026-09-01 (SEC-25): read access to sensitive documents (KYC images,
     * contract PDFs). The Auditable trait only sees writes; a reviewer opening
     * an identity document is an event compliance must be able to reconstruct.
     * Same table, action `viewed`, context in new_values — never the file path
     * (paths are redacted from write logs for the same reason).
     */
    public static function recordAccess(string $modelType, int $modelId, array $context = []): void
    {
        static::create([
            'user_id' => auth()->id(),
            'action' => 'viewed',
            'model_type' => $modelType,
            'model_id' => $modelId,
            'old_values' => null,
            'new_values' => $context ?: null,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
