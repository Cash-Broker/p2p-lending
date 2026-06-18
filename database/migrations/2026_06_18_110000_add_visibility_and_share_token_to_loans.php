<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private (unlisted) loans. A loan can be `private` — hidden from the public
 * marketplace and reachable only via a secret share link (share_token) the
 * admin sends to an investor. Visibility is orthogonal to status: a private
 * loan is still `published` (investable), just not listed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('visibility')->default('public')->after('status');
            // Unguessable secret embedded in the share link; null until the
            // admin generates one. Nullable + unique → many NULLs allowed (MySQL).
            $table->string('share_token', 64)->nullable()->unique()->after('visibility');
            $table->index('visibility');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropUnique(['share_token']);
            $table->dropIndex(['visibility']);
            $table->dropColumn(['visibility', 'share_token']);
        });
    }
};
