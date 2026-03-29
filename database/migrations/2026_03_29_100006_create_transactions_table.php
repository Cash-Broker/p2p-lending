<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Transactions are the financial ledger — immutable, append-only.
        // Every movement of money creates a transaction record.
        // No updated_at column — transactions are never modified.
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('type'); // deposit, withdrawal, investment, repayment_principal, repayment_interest, fee
            $table->decimal('amount', 12, 2);
            $table->string('description')->nullable();
            $table->string('reference')->nullable(); // links to deposit_request, investment, etc.
            $table->timestamp('created_at');

            $table->index(['user_id', 'type']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
