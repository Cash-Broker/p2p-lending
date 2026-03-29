<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amortization_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->date('due_date');
            $table->decimal('principal', 10, 2);
            $table->decimal('interest', 10, 2);
            $table->decimal('total', 10, 2);
            $table->string('status')->default('pending'); // pending, paid, late, default
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['loan_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amortization_schedules');
    }
};
