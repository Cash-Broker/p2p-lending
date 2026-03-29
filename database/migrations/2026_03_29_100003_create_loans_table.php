<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('originator_id')->constrained();
            $table->foreignId('borrower_id')->constrained();
            $table->decimal('amount', 12, 2);
            $table->decimal('funded_amount', 12, 2)->default(0.00);
            // Two interest rate columns:
            // interest_rate = the rate the investor earns
            // interest_rate_annual = the annual rate the borrower pays (may differ due to originator spread)
            $table->decimal('interest_rate', 5, 2);
            $table->decimal('interest_rate_annual', 5, 2);
            $table->unsignedInteger('term_months');
            $table->string('type'); // consumer, business, mortgage, bridge, etc.
            $table->string('status')->default('draft'); // draft, published, funding, funded, active, late, default, repaid
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
