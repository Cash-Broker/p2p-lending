<?php

namespace App\Console\Commands\Accounts;

use App\Models\PlatformMetric;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SEC-22 (owner 2026-09-03): anonymises the accounts whose confirmed deletion
 * request has waited out its period. Runs at 04:30 — after every money cron —
 * so the ledger is quiet when a wallet row disappears. Kill switch
 * `account_deletion_finalize_enabled`; a request that no longer qualifies is
 * cancelled by the service (not retried forever).
 */
class FinalizeAccountDeletions extends Command
{
    protected $signature = 'accounts:finalize-deletions
        {--dry-run : List the accounts that are due without anonymising anything}
        {--user= : Finalise a single user id}';

    protected $description = 'Finalise confirmed account-deletion requests whose waiting period has passed';

    private const LOCK_KEY = 'accounts:finalize-deletions';

    public function handle(AccountDeletionService $service): int
    {
        $lock = Cache::lock(self::LOCK_KEY, 600);
        if (! $lock->get()) {
            $this->error('Another accounts:finalize-deletions instance is already running. Exit.');

            return self::FAILURE;
        }

        try {
            if (! (bool) PlatformSetting::get('account_deletion_finalize_enabled', true)) {
                $this->warn('account_deletion_finalize_enabled = false — nothing finalised.');
                $this->writeMetrics('disabled', ['finalized' => 0, 'blocked' => 0, 'skipped' => 0, 'failed' => 0]);

                return self::SUCCESS;
            }

            $dryRun = (bool) $this->option('dry-run');
            $ids = User::query()
                ->where('role', 'investor')
                ->whereNotNull('deletion_confirmed_at')
                ->whereNull('deletion_finalized_at')
                ->where('deletion_scheduled_for', '<=', now())
                ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
                ->orderBy('id')
                ->pluck('id');

            $stats = ['finalized' => 0, 'blocked' => 0, 'skipped' => 0, 'failed' => 0];

            foreach ($ids as $id) {
                if ($dryRun) {
                    $this->line("Would finalise user #{$id}");
                    $stats['skipped']++;

                    continue;
                }

                try {
                    $user = User::find($id);
                    $outcome = $user === null ? 'skipped' : $service->finalize($user);
                    $stats[$outcome] = ($stats[$outcome] ?? 0) + 1;
                    $this->line("User #{$id}: {$outcome}");
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    Log::error('accounts:finalize-deletions failed for user', ['user_id' => $id, 'error' => $e->getMessage()]);
                    $this->error("User #{$id}: {$e->getMessage()}");
                }
            }

            $this->info(sprintf(
                '%s: %d finalised, %d blocked, %d skipped, %d failed.',
                $dryRun ? 'Dry run' : 'Done',
                $stats['finalized'], $stats['blocked'], $stats['skipped'], $stats['failed'],
            ));

            if (! $dryRun) {
                $this->writeMetrics($stats['failed'] > 0 ? 'failure' : 'ok', $stats);
            }

            return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /** @param  array<string, int>  $stats */
    private function writeMetrics(string $status, array $stats): void
    {
        try {
            PlatformMetric::record('last_account_deletions_run_at', now()->toIso8601String());
            PlatformMetric::record('last_account_deletions_status', $status);
            PlatformMetric::record('last_account_deletions_stats', json_encode($stats));
        } catch (\Throwable $e) {
            Log::warning('accounts:finalize-deletions metrics not recorded', ['error' => $e->getMessage()]);
        }
    }
}
