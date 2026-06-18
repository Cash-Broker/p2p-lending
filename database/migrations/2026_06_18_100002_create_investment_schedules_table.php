<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-investment payout schedule — the investor-facing source of truth for
 * offer-based loans. Mirrors `amortization_schedules` (the per-LOAN borrower
 * schedule) but is keyed to a single investment, because under the 3-offer
 * model each investor in the same loan can have a different rate + structure.
 *
 * Generated at funded → active for offer-based loans (see
 * Loan::transitionTo + InvestmentScheduleGenerator). Legacy loans (investments
 * with no offer) keep using amortization_schedules instead — both paths coexist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_id')->constrained()->cascadeOnDelete();
            // Denormalised for cheap loan-level queries (late detection, listing).
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->date('due_date');
            $table->decimal('principal', 12, 2);
            $table->decimal('interest', 12, 2);
            $table->decimal('total', 12, 2);
            $table->string('status')->default('pending'); // pending, paid, late
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('became_late_at')->nullable();
            $table->unsignedInteger('days_late')->default(0);
            $table->timestamps();

            $table->index(['investment_id', 'due_date']);
            $table->index(['loan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_schedules');
    }
};
