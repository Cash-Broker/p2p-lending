<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One device, several accounts (Yordan 2026-08-17).
 *
 * The package ships `endpoint` as globally UNIQUE, so a browser could belong
 * to exactly one user: Reni uses ONE phone for both the admin panel and her
 * investor profile, and whichever she opened last stole the device — the other
 * account's notifications silently stopped.
 *
 * Uniqueness moves to (endpoint + owner): the same browser may hold one
 * subscription per account, and both streams arrive. Revocation stays scoped —
 * a logout deletes only that account's row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('webpush.database_connection'))
            ->table(config('webpush.table_name'), function (Blueprint $table) {
                $table->dropUnique('push_subscriptions_endpoint_unique');
                $table->unique(
                    ['endpoint', 'subscribable_type', 'subscribable_id'],
                    'push_subscriptions_endpoint_owner_unique',
                );
            });
    }

    public function down(): void
    {
        Schema::connection(config('webpush.database_connection'))
            ->table(config('webpush.table_name'), function (Blueprint $table) {
                $table->dropUnique('push_subscriptions_endpoint_owner_unique');
                $table->unique('endpoint', 'push_subscriptions_endpoint_unique');
            });
    }
};
