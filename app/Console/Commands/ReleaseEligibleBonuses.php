<?php

namespace App\Console\Commands;

use App\Models\BonusGrant;
use App\Services\BonusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Daily sweep that releases conditional bonuses whose condition now holds
 * (Reni 2026-08-18: «важното е да се изпълнят условията» — no claim button,
 * no admin approval).
 *
 * Runs after the payout cron: that is what marks installments paid, so the
 * bonus becomes releasable in the same night the third payout lands.
 *
 * Every release is its own locked, idempotent transaction inside
 * BonusService, so a crash mid-sweep loses nothing and a re-run is safe.
 */
class ReleaseEligibleBonuses extends Command
{
    protected $signature = 'bonuses:release-eligible
        {--dry-run : Report what would be released without moving money}
        {--user= : Evaluate a single user id}';

    protected $description = 'Release conditional bonuses whose investment condition is now met';

    private const LOCK_KEY = 'bonuses:release-eligible';

    public function handle(BonusService $bonusService): int
    {
        $lock = Cache::lock(self::LOCK_KEY, 600);

        if (! $lock->get()) {
            $this->error('Another bonuses:release-eligible instance is already running. Exit.');

            return self::FAILURE;
        }

        try {
            $userIds = BonusGrant::query()
                ->locked()
                ->when($this->option('user'), fn ($query, $userId) => $query->where('user_id', $userId))
                ->distinct()
                ->orderBy('user_id')
                ->pluck('user_id');

            $releasedCount = 0;
            $releasedTotal = '0.00';
            $failed = 0;

            foreach ($userIds as $userId) {
                try {
                    if ($this->option('dry-run')) {
                        $releasedCount += $this->reportDryRun($bonusService, (int) $userId);

                        continue;
                    }

                    foreach ($bonusService->evaluateUser((int) $userId) as $grant) {
                        $releasedCount++;
                        $releasedTotal = bcadd($releasedTotal, (string) $grant->amount, 2);
                        $this->line("Released bonus #{$grant->id} ({$grant->amount} €) for user #{$userId}.");
                    }
                } catch (\Throwable $e) {
                    // One investor's broken state must not stop the sweep.
                    $failed++;
                    Log::error('bonuses:release-eligible failed for user', [
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                    $this->error("User #{$userId}: {$e->getMessage()}");
                }
            }

            $this->info(sprintf(
                '%s: %d bonus(es), %s €, %d user(s) failed.',
                $this->option('dry-run') ? 'Would release' : 'Released',
                $releasedCount,
                $releasedTotal,
                $failed,
            ));

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /** Evaluate without moving money — same condition, read-only. */
    private function reportDryRun(BonusService $bonusService, int $userId): int
    {
        $count = 0;

        foreach (BonusGrant::where('user_id', $userId)->locked()->orderBy('id')->get() as $grant) {
            $qualified = $bonusService->qualifiedInvestedAmount(
                $userId,
                $grant->qualifies_from,
                $grant->required_installments,
            );

            if (bccomp($qualified, (string) $grant->base_amount, 2) >= 0) {
                $count++;
                $this->line("Would release bonus #{$grant->id} ({$grant->amount} €) for user #{$userId}"
                    ." — qualified {$qualified} € of {$grant->base_amount} €.");
            }
        }

        return $count;
    }
}
