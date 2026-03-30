<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legal evidence that the user agreed to specific terms.
        // Without this, an investor can claim "I never agreed to the risk disclosure"
        // and we have no defense. Each document version is tracked separately.
        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // terms_of_service, privacy_policy, risk_disclosure
            $table->string('version'); // v1.0, v2.0 — tracks which version was accepted
            $table->string('ip_address', 45);
            $table->text('user_agent');
            $table->timestamp('accepted_at');

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
    }
};
