<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // IP + User-Agent on financial records = forensic evidence.
        // When an investor disputes a transaction ("I didn't do this"),
        // we can prove which device and IP initiated the action.
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('reference');
            $table->text('user_agent')->nullable()->after('ip_address');
        });

        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('confirmed_at');
            $table->text('user_agent')->nullable()->after('ip_address');
        });

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('processed_at');
            $table->text('user_agent')->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent']);
        });

        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent']);
        });

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent']);
        });
    }
};
