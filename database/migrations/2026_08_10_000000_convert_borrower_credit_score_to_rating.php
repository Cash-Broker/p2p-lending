<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Кредитен рейтинг» becomes the client's letter scale (Reni, 2026-08-10):
 * A — топ, B — много добър, C — добър. «Ние нямаме слаб» — three grades
 * only, chosen from a dropdown instead of typing a number.
 *
 * The column keeps its name (credit_score) but changes integer → string(1).
 * Existing numeric scores are mapped by the classic score bands so no row
 * loses its rating: 750+ → A, 650-749 → B, below → C. The thresholds are
 * a one-off conversion aid for pre-launch data, not business logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Three steps: widen so existing 3-digit scores survive the type
        // change (strict mode truncation error otherwise), convert to
        // letters, then shrink to the final 1-char scale.
        Schema::table('borrowers', function (Blueprint $table) {
            $table->string('credit_score', 3)->nullable()->change();
        });

        DB::statement("
            UPDATE borrowers
            SET credit_score = CASE
                WHEN CAST(credit_score AS UNSIGNED) >= 750 THEN 'A'
                WHEN CAST(credit_score AS UNSIGNED) >= 650 THEN 'B'
                ELSE 'C'
            END
            WHERE credit_score IS NOT NULL
        ");

        Schema::table('borrowers', function (Blueprint $table) {
            $table->string('credit_score', 1)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Letters back to representative numbers (widen first), then the
        // column type.
        Schema::table('borrowers', function (Blueprint $table) {
            $table->string('credit_score', 3)->nullable()->change();
        });

        DB::statement("
            UPDATE borrowers
            SET credit_score = CASE credit_score
                WHEN 'A' THEN '800'
                WHEN 'B' THEN '700'
                WHEN 'C' THEN '600'
                ELSE NULL
            END
            WHERE credit_score IS NOT NULL
        ");

        Schema::table('borrowers', function (Blueprint $table) {
            $table->integer('credit_score')->nullable()->change();
        });
    }
};
