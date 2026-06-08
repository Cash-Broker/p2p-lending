<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing single-document submissions become the "front" image so we
        // don't lose any already-uploaded KYC scans. The back column is added
        // alongside and left null for legacy records (admins can request it).
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('kyc_document_path', 'kyc_document_front_path');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('kyc_document_back_path')->nullable()->after('kyc_document_front_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('kyc_document_back_path');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('kyc_document_front_path', 'kyc_document_path');
        });
    }
};
