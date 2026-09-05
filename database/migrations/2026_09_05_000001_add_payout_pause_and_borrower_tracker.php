<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAY-13 (owner 2026-09-03): late detection for OFFER loans + payout pause.
 *
 * ADDITIVE ONLY — nullable columns, two setting rows, no UPDATE of existing
 * data, no CHECK, no trigger. Legacy amortization rows keep plan_kind NULL and
 * byte-identical semantics; the borrower tracker of an offer loan is
 * plan_kind = 'borrower_tracker' (see AmortizationSchedule / BorrowerPlanService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            // Stamped by PayoutPauseService when the borrower is late past the
            // threshold; the engine skips a stamped loan only while
            // payout_pause_enabled is on (Loan::isPayoutPaused()).
            $table->timestamp('payouts_paused_at')->nullable()->after('became_late_at')->index();
        });

        Schema::table('amortization_schedules', function (Blueprint $table) {
            $table->string('plan_kind', 32)->nullable()->after('loan_id');
            $table->date('borrower_paid_on')->nullable()->after('paid_at');
            // Admin who attested the borrower payment — plain id, no FK (same
            // convention as withdrawal_requests.approved_by: evidence must outlive
            // an anonymised admin row).
            $table->unsignedBigInteger('recorded_by')->nullable()->after('borrower_paid_on');
            $table->index(['loan_id', 'plan_kind']);
        });

        foreach ([
            ['payout_pause_enabled', 'false', 'bool', 'Спиране на плащанията към инвеститорите по кредит, чийто кредитополучател е в закъснение над прага (payout_pause_late_days). Изключено = платформата плаща по график независимо от кредитополучателя (решение на Рени). Включено = след прага дните авансирането спира за закъснелите кредити; изключването пуска парите при следващото плащане.'],
            ['payout_pause_late_days', '30', 'int', 'Брой дни след като първата вноска на кредитополучателя стане закъсняла, преди платформата да СПРЕ да плаща инвеститорите по график за този кредит. Действа само при включено payout_pause_enabled. 0 = още същата нощ.'],
            // Owner 2026-09-05: Reni will not record borrower installments by hand, so a
            // tracker must not appear on its own — until an automation (statement import
            // or an exception-driven «не плати» flag) is chosen, PAY-13 stays dormant.
            ['borrower_tracker_auto_generate', 'false', 'bool', 'Създава ли се планът на кредитополучателя автоматично при активиране на офертен кредит. Изключено = планове има само там, където админ ги е създал ръчно; без план кредитът не може да стане „закъснял“ автоматично и инвеститорите не получават имейли за закъснение.'],
        ] as [$key, $value, $type, $description]) {
            DB::table('platform_settings')->insertOrIgnore([
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('platform_settings')->whereIn('key', ['payout_pause_enabled', 'payout_pause_late_days', 'borrower_tracker_auto_generate'])->delete();

        Schema::table('amortization_schedules', function (Blueprint $table) {
            $table->dropIndex(['loan_id', 'plan_kind']);
            $table->dropColumn(['plan_kind', 'borrower_paid_on', 'recorded_by']);
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex(['payouts_paused_at']);
            $table->dropColumn('payouts_paused_at');
        });
    }
};
