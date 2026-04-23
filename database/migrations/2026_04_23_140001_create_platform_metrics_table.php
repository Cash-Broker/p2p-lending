<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Metrics — observed state of the system, written by automated jobs.
 *
 * Distinct from `platform_settings` (configurable). A metric is something
 * the system measures and stamps with a timestamp. Examples:
 *   last_late_check_run_at, last_late_check_loans_scanned,
 *   last_late_check_schedules_marked, …
 *
 * Storage shape mirrors platform_settings (key/value/timestamp) so the same
 * read pattern works in admin dashboards.
 *
 * Not Auditable — these are observability data, not configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            // When the metric was last updated by the producing job.
            $table->timestamp('measured_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_metrics');
    }
};
