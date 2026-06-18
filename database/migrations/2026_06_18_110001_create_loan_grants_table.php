<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that an investor has been granted access to a PRIVATE loan by
 * opening its share link. Needed because after the link is opened the SPA
 * navigates to /invest/{id} and subsequent API calls carry no token — the
 * grant is what lets LoanPolicy::view pass for that user thereafter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['loan_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_grants');
    }
};
