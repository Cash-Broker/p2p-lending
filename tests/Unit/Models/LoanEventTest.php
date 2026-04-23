<?php

namespace Tests\Unit\Models;

use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\Originator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase F1 Step 2 — LoanEvent immutability across all three layers:
 *   1. application: update()/delete() throw LogicException
 *   2. database: triggers block raw SQL UPDATE/DELETE
 *   3. constraints: CHECK rules reject malformed inserts
 */
class LoanEventTest extends TestCase
{
    use RefreshDatabase;

    private function makeEvent(): LoanEvent
    {
        $orig = Originator::create(['name' => 'F1 Event Test', 'description' => 'X', 'buyback' => false]);
        $borrower = Borrower::create([
            'full_name' => 'B', 'personal_id' => '0', 'address' => 'A', 'phone' => '+1', 'income' => '1000',
        ]);
        BorrowerAnonymizedProfile::create([
            'borrower_id' => $borrower->id, 'risk_class' => 'B', 'region' => 'X',
            'loan_purpose' => 'X', 'collateral_type' => '—', 'age_group' => '30-40',
        ]);
        $loan = Loan::create([
            'originator_id' => $orig->id, 'borrower_id' => $borrower->id,
            'amount' => '1000', 'funded_amount' => '1000',
            'interest_rate' => '12', 'interest_rate_annual' => '15',
            'term_months' => 6, 'type' => 'consumer', 'status' => 'draft',
        ]);
        return LoanEvent::create([
            'loan_id' => $loan->id, 'event_type' => 'went_late',
            'from_status' => 'active', 'to_status' => 'late',
            'triggered_by' => 'system', 'triggered_by_user_id' => null,
            'metadata' => ['days_late_at_transition' => 12],
            'occurred_at' => now(),
        ]);
    }

    public function test_application_level_update_throws(): void
    {
        $event = $this->makeEvent();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('LoanEvent records are immutable');
        $event->update(['event_type' => 'status_changed']);
    }

    public function test_application_level_delete_throws(): void
    {
        $event = $this->makeEvent();

        $this->expectException(\LogicException::class);
        $event->delete();
    }

    public function test_db_trigger_blocks_raw_sql_update(): void
    {
        $event = $this->makeEvent();

        $this->expectExceptionMessage('loan_events are immutable');
        DB::statement("UPDATE loan_events SET event_type = 'status_changed' WHERE id = {$event->id}");
    }

    public function test_db_check_constraint_rejects_invalid_event_type(): void
    {
        $event = $this->makeEvent();

        $this->expectExceptionMessage('chk_loan_events_event_type');
        DB::statement("INSERT INTO loan_events (loan_id, event_type, triggered_by, occurred_at, created_at) VALUES ({$event->loan_id}, 'totally_made_up_type', 'system', NOW(), NOW())");
    }

    public function test_db_check_constraint_rejects_self_transition(): void
    {
        $event = $this->makeEvent();

        $this->expectExceptionMessage('chk_loan_events_status_pair');
        DB::statement("INSERT INTO loan_events (loan_id, event_type, from_status, to_status, triggered_by, occurred_at, created_at) VALUES ({$event->loan_id}, 'status_changed', 'late', 'late', 'system', NOW(), NOW())");
    }
}
