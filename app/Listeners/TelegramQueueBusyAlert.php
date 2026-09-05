<?php

namespace App\Listeners;

use App\Models\PlatformMetric;
use App\Services\TelegramService;
use App\Support\OpsAlert;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Audit 2026-09-01 (A3) — the queue is the delivery path for every admin alert
 * (KYC, withdrawals, registrations, buyback) and every investor mail. When the
 * worker stops, nothing complains: the jobs simply pile up. `queue:monitor`
 * (scheduled every 15 minutes) raises QueueBusy once the backlog passes its
 * threshold; this listener turns that into a 🟠 Telegram message and a metric
 * the health endpoint exposes.
 *
 * Registered in AppServiceProvider::boot() — automatic listener discovery is
 * OFF (see EventListenerRegistrationTest).
 */
class TelegramQueueBusyAlert
{
    public function __construct(private TelegramService $telegram) {}

    public function handle(QueueBusy $event): void
    {
        try {
            PlatformMetric::record('last_queue_busy_at', now()->toIso8601String());
            PlatformMetric::record('last_queue_busy_size', (string) $event->size);
        } catch (Throwable $e) {
            Log::warning('QueueBusy metric write failed', ['error' => $e->getMessage()]);
        }

        Log::warning('Queue backlog above threshold', [
            'connection' => $event->connectionName,
            'queue' => $event->queue,
            'size' => $event->size,
        ]);

        $this->telegram->high(
            'Опашката е задръстена',
            sprintf(
                '%d чакащи задачи в %s:%s. Провери дали Supervisor работникът (p2p-worker) върви — известията и имейлите не се доставят, докато стои.',
                $event->size,
                $event->connectionName,
                $event->queue,
            ),
            ['size' => $event->size],
        );

        OpsAlert::mail(
            'Опашката е задръстена',
            sprintf(
                '%d чакащи задачи в %s:%s. Провери Supervisor работника (p2p-worker) — известията и имейлите не се доставят, докато стои.',
                $event->size,
                $event->connectionName,
                $event->queue,
            ),
        );
    }
}
