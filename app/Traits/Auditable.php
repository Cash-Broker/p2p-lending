<?php

namespace App\Traits;

use App\Models\AuditLog;
use App\Models\User;

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
        // Strip sensitive fields from audit log — we log THAT it changed, not the value.
        // Borrower PII (full_name, address, phone) is encrypted at rest; redacting
        // it from audit logs avoids re-exposure when auditors / support read logs.
        // Audit 2026-09-01: extended with the legal-entity identifiers, KYC file
        // paths and bank references — audit_logs is the one table an auditor
        // reads in bulk, so it must not become a second copy of the PII.
        $sensitiveFields = [
            'password', 'remember_token',
            'personal_id', 'iban',
            'full_name', 'address', 'phone',
            'eik', 'vat_number', 'legal_name', 'representative_egn', 'national_id',
            'kyc_document_front_path', 'kyc_document_back_path', 'kyc_selfie_path',
            'bank_reference',
            'confirmation_token_hash',
            'consent_snapshot', 'subject_snapshot',
        ];
        foreach ($sensitiveFields as $field) {
            if (isset($oldValues[$field])) {
                $oldValues[$field] = '[REDACTED]';
            }
            if (isset($newValues[$field])) {
                $newValues[$field] = '[REDACTED]';
            }
        }

        // E-mail changes are exactly what an account-takeover investigation needs
        // to see, so keep the shape (first character + domain) and mask the rest.
        if (isset($oldValues['email']) && is_string($oldValues['email'])) {
            $oldValues['email'] = self::maskEmailForAudit($oldValues['email']);
        }
        if (isset($newValues['email']) && is_string($newValues['email'])) {
            $newValues['email'] = self::maskEmailForAudit($newValues['email']);
        }

        // The investor's own name (review 2026-09-05): an anonymised account must
        // not stay reconstructible from this immutable table — initials keep the
        // account-takeover value («И*** П***»). Company/borrower names have their
        // own redacted columns above.
        if ($this instanceof User) {
            if (isset($oldValues['name']) && is_string($oldValues['name'])) {
                $oldValues['name'] = self::maskNameForAudit($oldValues['name']);
            }
            if (isset($newValues['name']) && is_string($newValues['name'])) {
                $newValues['name'] = self::maskNameForAudit($newValues['name']);
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

    protected static function maskEmailForAudit(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '[REDACTED]';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }

    protected static function maskNameForAudit(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];

        return implode(' ', array_map(fn (string $word) => $word === '' ? '' : mb_substr($word, 0, 1).'***', $words));
    }
}
