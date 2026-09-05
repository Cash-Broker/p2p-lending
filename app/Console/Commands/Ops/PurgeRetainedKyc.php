<?php

namespace App\Console\Commands\Ops;

use App\Services\KycRetentionService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SEC-16 (owner 2026-09-03): deletes the archived KYC files and blanks the
 * snapshots of closed accounts whose retention clock has run out. 05:00, after
 * every money cron. Kill switch `kyc_retention_purge_enabled` (off = over-
 * retain, nothing is lost). Rows are never deleted — they prove the purge.
 */
class PurgeRetainedKyc extends Command
{
    protected $signature = 'kyc:purge-retained
        {--dry-run : List the archives that are due without deleting anything}
        {--retention= : Purge a single archive id}';

    protected $description = 'Purge KYC archives of closed accounts whose retention period has passed';

    private const LOCK_KEY = 'kyc:purge-retained';

    public function handle(KycRetentionService $service): int
    {
        $lock = Cache::lock(self::LOCK_KEY, 600);
        if (! $lock->get()) {
            $this->error('Another kyc:purge-retained instance is already running. Exit.');

            return self::FAILURE;
        }

        try {
            if (! KycRetentionService::purgeEnabled()) {
                $this->warn('kyc_retention_purge_enabled = false — nothing purged.');

                return self::SUCCESS;
            }

            $dryRun = (bool) $this->option('dry-run');
            $onlyId = $this->option('retention') !== null ? (int) $this->option('retention') : null;

            $result = $service->purgeDue(today(), $dryRun, $onlyId);

            foreach ($result['ids'] as $id) {
                $this->line(($dryRun ? 'Would purge' : 'Purged')." KYC archive #{$id}");
            }
            $this->info(sprintf(
                '%s: %d archive(s) %s, %d failed.',
                $dryRun ? 'Dry run' : 'Done',
                $dryRun ? count($result['ids']) : $result['purged'],
                $dryRun ? 'due' : 'purged',
                $result['failed'],
            ));

            if (! $dryRun && $result['purged'] > 0) {
                try {
                    app(TelegramService::class)->info(
                        'Заличени KYC архиви',
                        sprintf('%d архив(а) с изтекъл срок по ЗМИП са заличени.', $result['purged']),
                    );
                } catch (\Throwable $e) {
                    Log::warning('kyc:purge-retained Telegram failed', ['error' => $e->getMessage()]);
                }
            }

            return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
