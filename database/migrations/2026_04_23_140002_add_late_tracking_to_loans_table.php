<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds late-state tracking to the loans table.
 *
 *  last_late_check_at — when the loans:process-late command last evaluated
 *                       this loan. Used to:
 *                         (a) skip loans rechecked too recently in support runs
 *                         (b) detect "loan never evaluated" alerts
 *
 *  became_late_at     — set when the loan transitions active → late.
 *                       Cleared (set null) on recovery (late → active).
 *                       Read by Filament UI for "went late X days ago".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->timestamp('last_late_check_at')->nullable()->after('published_at');
            $table->timestamp('became_late_at')->nullable()->after('last_late_check_at');

            // Useful for "find all loans that went late since X" queries.
            $table->index('became_late_at');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex(['became_late_at']);
            $table->dropColumn(['last_late_check_at', 'became_late_at']);
        });
    }
};
