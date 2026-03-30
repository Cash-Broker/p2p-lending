<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrowers', function (Blueprint $table) {
            $table->id();
            // PII fields use text() because Laravel's encrypted cast produces
            // ciphertext longer than 255 chars (base64 + IV + MAC)
            $table->text('full_name');  // Encrypted at rest
            $table->text('personal_id'); // Encrypted at rest
            $table->text('address');     // Encrypted at rest
            $table->text('phone');       // Encrypted at rest
            $table->decimal('income', 12, 2);
            $table->integer('credit_score')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrowers');
    }
};
