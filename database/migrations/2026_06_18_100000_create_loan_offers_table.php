<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three-offers-per-loan feature. Each loan can be offered to investors under
 * up to three payout structures (amortizing / interest-only / capitalized),
 * each with its own annual rate.
 *
 * Offers live in their OWN table — NOT on `loans` — precisely because
 * Loan::IMMUTABLE_AFTER_DRAFT freezes interest_rate/term_months once the loan
 * leaves draft. A separate table lets the boss keep editing offer rates on
 * already-published loans (until funding starts), which the loan row forbids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            // PayoutType: amortizing | interest_only | capitalized
            $table->string('payout_type');
            // Investor annual yield for this offer. Free number set per loan by
            // the boss (12/16/20 are only seeded defaults).
            $table->decimal('interest_rate', 5, 2);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            // One offer per payout type per loan; lets the backfill + auto-seed
            // be insertOrIgnore-safe and re-runnable.
            $table->unique(['loan_id', 'payout_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_offers');
    }
};
