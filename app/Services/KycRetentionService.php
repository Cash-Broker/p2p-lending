<?php

namespace App\Services;

use App\Models\KycRetention;
use App\Models\PlatformSetting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * SEC-16 (owner 2026-09-03): identity documents and consent records of a
 * closed account survive in a compliance-only archive for
 * `kyc_retention_years` (default 5, ЗМИП чл. 67) and are purged afterwards.
 *
 * Copy-then-delete on purpose: a filesystem move cannot roll back, a copy
 * leaves the originals in place if the closure transaction fails after the
 * copy — the caller's existing after-commit `Storage::delete($originals)` is
 * the step that releases them. The `local` disk has `throw => false`
 * (config/filesystems.php), so every Storage call's boolean MUST be checked;
 * a full disk must refuse the closure, never anonymise with an empty archive.
 */
class KycRetentionService
{
    public const DIRECTORY = 'kyc-retained';

    public const DEFAULT_RETENTION_YEARS = 5;

    public static function retentionYears(): int
    {
        return max(1, (int) PlatformSetting::get('kyc_retention_years', self::DEFAULT_RETENTION_YEARS));
    }

    public static function purgeEnabled(): bool
    {
        return (bool) PlatformSetting::get('kyc_retention_purge_enabled', true);
    }

    /**
     * MUST run inside the closure transaction, after every eligibility guard and
     * before the anonymising forceFill (the subject snapshot needs the real
     * values). Returns null when there is nothing to keep. Idempotent per user.
     */
    public function retain(User $user): ?KycRetention
    {
        $consents = $user->consentRecords()->orderBy('id')->get()->map(fn ($c) => [
            'type' => $c->type,
            'version' => $c->version,
            'ip_address' => $c->ip_address,
            'user_agent' => $c->user_agent,
            'accepted_at' => optional($c->getAttribute('accepted_at') ?? $c->created_at)->toIso8601String(),
        ])->all();

        $sources = [
            KycRetention::KIND_FRONT => $user->kyc_document_front_path,
            KycRetention::KIND_BACK => $user->kyc_document_back_path,
            KycRetention::KIND_SELFIE => $user->kyc_selfie_path,
        ];
        $sources = array_filter($sources);

        if ($sources === [] && $consents === []) {
            return null;
        }

        $existing = KycRetention::where('user_id', $user->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        // A retried finalisation (deadlock) copies again — leftovers of the aborted
        // attempt would otherwise sit in the directory outside every row's paths.
        $this->discardCopies((int) $user->id);

        $disk = Storage::disk('local');
        $stored = [];
        foreach ($sources as $kind => $src) {
            if (! $disk->exists($src)) {
                Log::warning('KYC retention: source file missing, archived without it', ['user_id' => $user->id, 'kind' => $kind]);

                continue;
            }
            $dst = self::DIRECTORY.'/'.$user->id.'/'.$kind.'-'.basename($src);
            if (! $disk->copy($src, $dst)) {
                throw new RuntimeException("KYC retention copy failed for user #{$user->id} ({$kind}) — closure refused.");
            }
            $stored[$kind] = $dst;
        }

        $years = self::retentionYears();

        return KycRetention::create([
            'user_id' => $user->id,
            'account_type' => (string) $user->account_type,
            'kyc_status_at_deletion' => (string) $user->kyc_status,
            'kyc_document_front_path' => $stored[KycRetention::KIND_FRONT] ?? null,
            'kyc_document_back_path' => $stored[KycRetention::KIND_BACK] ?? null,
            'kyc_selfie_path' => $stored[KycRetention::KIND_SELFIE] ?? null,
            'consent_snapshot' => $consents,
            'subject_snapshot' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'account_type' => $user->account_type,
            ],
            'retention_years' => $years,
            'retained_until' => today()->addYears($years),
        ]);
    }

    /** Rollback compensation: never throws. */
    public function discardCopies(int $userId): void
    {
        try {
            Storage::disk('local')->deleteDirectory(self::DIRECTORY.'/'.$userId);
        } catch (Throwable $e) {
            Log::warning('KYC retention: could not discard copies', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Absolute path of one archived file, or null when there is none. The path
     * comes from the row, never from the URL; it still has to live under the
     * archive directory (a hand-edited row must not become a file-read primitive).
     */
    public function absoluteDocumentPath(KycRetention $retention, string $kind): ?string
    {
        $path = $retention->documentPath($kind);
        if ($path === null || ! str_starts_with($path, self::DIRECTORY.'/')) {
            return null;
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return null;
        }

        $base = realpath($disk->path(self::DIRECTORY)) ?: $disk->path(self::DIRECTORY);
        $abs = realpath($disk->path($path)) ?: $disk->path($path);
        if (! str_starts_with($abs, $base)) {
            abort(403);
        }

        return $abs;
    }

    /**
     * Files first, then the row: a crash in between leaves an active row whose
     * files are gone — the next run tolerates the missing files and stamps it.
     * The opposite order would leave undocumented evidence on disk past the clock.
     */
    public function purge(KycRetention $retention, ?int $adminId = null, ?CarbonInterface $asOf = null): bool
    {
        return DB::transaction(function () use ($retention, $adminId, $asOf): bool {
            $fresh = KycRetention::whereKey($retention->id)->lockForUpdate()->firstOrFail();
            if ($fresh->isPurged() || ! $fresh->isDue($asOf)) {
                return false;
            }

            $disk = Storage::disk('local');
            foreach (KycRetention::KIND_COLUMNS as $kind => $column) {
                $path = $fresh->{$column};
                if ($path === null) {
                    continue;
                }
                if (! $disk->exists($path)) {
                    Log::warning('KYC purge: file already missing', ['retention_id' => $fresh->id, 'kind' => $kind]);

                    continue;
                }
                if (! $disk->delete($path)) {
                    throw new RuntimeException("KYC purge: could not delete {$kind} of archive #{$fresh->id}.");
                }
            }
            $this->discardCopies((int) $fresh->user_id);

            $fresh->forceFill([
                'kyc_document_front_path' => null,
                'kyc_document_back_path' => null,
                'kyc_selfie_path' => null,
                'consent_snapshot' => null,
                'subject_snapshot' => null,
                'purged_at' => now(),
                'purged_by' => $adminId,
            ])->save();

            return true;
        });
    }

    /**
     * @return array{purged: int, failed: int, ids: array<int, int>}
     */
    public function purgeDue(CarbonInterface $asOf, bool $dryRun = false, ?int $onlyId = null): array
    {
        $result = ['purged' => 0, 'failed' => 0, 'ids' => []];

        $rows = KycRetention::due($asOf)
            ->when($onlyId !== null, fn ($q) => $q->whereKey($onlyId))
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if ($dryRun) {
                $result['ids'][] = (int) $row->id;

                continue;
            }

            try {
                if ($this->purge($row, null, $asOf)) {
                    $result['purged']++;
                    $result['ids'][] = (int) $row->id;
                }
            } catch (Throwable $e) {
                $result['failed']++;
                Log::error('KYC purge failed for archive', ['retention_id' => $row->id, 'error' => $e->getMessage()]);
            }
        }

        return $result;
    }
}
