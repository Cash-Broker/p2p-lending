<?php

namespace App\Models;

use App\Traits\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * SEC-16 (owner 2026-09-03): the compliance archive of a closed account.
 *
 * At closure the identity files are copied to `kyc-retained/{user_id}/…` and the
 * consent ledger + a minimal subject snapshot are frozen here (encrypted), with
 * a clock of `kyc_retention_years` (default 5 — ЗМИП чл. 67, GDPR Art. 17(3)(b)).
 * `kyc:purge-retained` deletes the files and blanks the snapshots when the
 * clock runs out; the row itself is never deleted — it is the proof the purge
 * happened.
 *
 * App-level immutability like InvestmentContract: the only legal update is the
 * purge transition (purged_at NULL → set, paths/snapshots cleared). Query-
 * builder / raw SQL writes are not covered — a DB trigger needs sign-off.
 */
class KycRetention extends Model
{
    use Auditable;

    public const KIND_FRONT = 'front';

    public const KIND_BACK = 'back';

    public const KIND_SELFIE = 'selfie';

    public const KIND_COLUMNS = [
        self::KIND_FRONT => 'kyc_document_front_path',
        self::KIND_BACK => 'kyc_document_back_path',
        self::KIND_SELFIE => 'kyc_selfie_path',
    ];

    private const PURGE_WRITABLE = [
        'kyc_document_front_path', 'kyc_document_back_path', 'kyc_selfie_path',
        'consent_snapshot', 'subject_snapshot', 'purged_at', 'purged_by', 'updated_at',
    ];

    protected $fillable = [
        'user_id',
        'account_type',
        'kyc_status_at_deletion',
        'kyc_document_front_path',
        'kyc_document_back_path',
        'kyc_selfie_path',
        'consent_snapshot',
        'subject_snapshot',
        'retention_years',
        'retained_until',
        'purged_at',
        'purged_by',
    ];

    protected function casts(): array
    {
        return [
            'consent_snapshot' => 'encrypted:array',
            'subject_snapshot' => 'encrypted:array',
            'retained_until' => 'date',
            'purged_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('purged_at');
    }

    public function scopeDue(Builder $query, CarbonInterface $asOf): Builder
    {
        return $query->whereNull('purged_at')->whereDate('retained_until', '<=', $asOf->toDateString());
    }

    public function isPurged(): bool
    {
        return $this->purged_at !== null;
    }

    public function isDue(?CarbonInterface $asOf = null): bool
    {
        return ! $this->isPurged() && $this->retained_until !== null
            && $this->retained_until->lessThanOrEqualTo(($asOf ?? now())->copy()->startOfDay());
    }

    public function documentPath(string $kind): ?string
    {
        $column = self::KIND_COLUMNS[$kind] ?? null;

        return $column === null ? null : $this->{$column};
    }

    /**
     * The only legal write after creation is the purge transition.
     */
    protected function performUpdate(Builder $query): bool
    {
        $dirty = array_keys($this->getDirty());
        $illegal = array_diff($dirty, self::PURGE_WRITABLE);
        $isPurgeTransition = in_array('purged_at', $dirty, true)
            && $this->getOriginal('purged_at') === null
            && $this->purged_at !== null;

        if ($illegal !== [] || ! $isPurgeTransition) {
            throw new LogicException(
                "KycRetention #{$this->getKey()} is immutable — only the purge transition may be written (dirty: ".implode(', ', $dirty).').'
            );
        }

        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel(): void
    {
        throw new LogicException("KycRetention #{$this->getKey()} rows are never deleted — a purged row is the proof the clock ran.");
    }
}
