<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Liveness/face-match selfie, required alongside the ID-card front/back
        // so the admin can confirm the document belongs to the person.
        Schema::table('users', function (Blueprint $table) {
            $table->string('kyc_selfie_path')->nullable()->after('kyc_document_back_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('kyc_selfie_path');
        });
    }
};
