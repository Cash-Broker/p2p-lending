<?php

namespace App\Traits;

use App\Models\AuditLog;

/**
 * Automatically logs create/update/delete events on a model.
 *
 * Add `use Auditable;` to any model that handles financial data
 * or PII to maintain a compliance-ready audit trail.
 *
 * The trait hooks into Eloquent's model events — no manual logging needed.
 * Each log entry captures: who, what changed, from where (IP + UA).
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->logAudit('created', [], $model->getAttributes());
        });

        static::updated(function ($model) {
            $dirty = $model->getDirty();
            if (empty($dirty)) {
                return;
            }

            $old = array_intersect_key($model->getOriginal(), $dirty);
            $model->logAudit('updated', $old, $dirty);
        });

        static::deleted(function ($model) {
            $model->logAudit('deleted', $model->getOriginal(), []);
        });
    }

    protected function logAudit(string $action, array $oldValues, array $newValues): void
    {
        // Strip sensitive fields from audit log — we log THAT it changed, not the value
        $sensitiveFields = ['password', 'remember_token', 'personal_id', 'iban'];
        foreach ($sensitiveFields as $field) {
            if (isset($oldValues[$field])) {
                $oldValues[$field] = '[REDACTED]';
            }
            if (isset($newValues[$field])) {
                $newValues[$field] = '[REDACTED]';
            }
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'model_type' => static::class,
            'model_id' => $this->getKey(),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
