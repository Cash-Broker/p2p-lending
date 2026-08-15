<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-only visit analytics (Yordan 2026-08-15): one row per investor per
 * Sofia calendar day, counting dashboard ENTRIES (a new entry = first load
 * or a load 30+ min after the previous one). Deliberately minimal — no IPs,
 * no durations, no per-hit rows — just enough for «влизал X пъти тази
 * седмица» in the admin and the digest line. GDPR: aggregate counter under
 * legitimate interest; per-action forensics remain in audit_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_visit_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('visit_date');
            $table->unsignedInteger('entries')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'visit_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_visit_days');
    }
};
