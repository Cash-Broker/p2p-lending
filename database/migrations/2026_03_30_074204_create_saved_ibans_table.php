<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_ibans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('iban'); // Encrypted at rest
            $table->string('label')->nullable(); // "Основна сметка", "Спестовна" etc.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_ibans');
    }
};
