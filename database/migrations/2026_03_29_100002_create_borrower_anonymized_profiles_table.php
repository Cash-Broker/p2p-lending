<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrower_anonymized_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('risk_class'); // A, B, C, D, E
            $table->string('region');
            $table->string('loan_purpose');
            $table->string('collateral_type')->nullable();
            $table->string('age_group')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrower_anonymized_profiles');
    }
};
