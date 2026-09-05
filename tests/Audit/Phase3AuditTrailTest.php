<?php

namespace Tests\Audit;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\Originator;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InvestmentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 Step 4 — Audit trail coverage audit.
 *
 * Verifies the audit plumbing works end-to-end AND documents the
 * known coverage gaps as change-detector tests.
 *
 * Findings: P3-F8, P3-F9 (both LOW, documentation-only).
 */
class Phase3AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────
    // Positive coverage confirmation
    // ──────────────────────────────────────────────────────────────

    public function test_platform_setting_update_writes_audit_log(): void
    {
        // PlatformSetting uses Auditable trait → every update writes
        // to audit_logs with old/new values, admin, IP, UA.
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $setting = PlatformSetting::where('key', 'grace_period_days')->firstOrFail();
        $setting->update(['value' => '7']); // was 10

        $audit = AuditLog::where('model_type', PlatformSetting::class)
            ->where('model_id', $setting->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('10', $audit->old_values['value']);
        $this->assertSame('7', $audit->new_values['value']);
    }

    public function test_wallet_updates_are_audited(): void
    {
        // Wallet uses Auditable trait. Any forceFill save creates an
        // audit_logs row.
        $user = User::factory()->kycApproved()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $wallet = Wallet::firstOrCreate(['user_id' => $user->id]);
        $wallet->forceFill(['available' => '100.00'])->save();

        $audit = AuditLog::where('model_type', Wallet::class)
            ->where('model_id', $wallet->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertArrayHasKey('available', $audit->new_values);
    }

    public function test_loan_status_transition_creates_audit_log_row(): void
    {
        // Loan uses Auditable trait. Any status transition writes to
        // audit_logs (distinct from loan_events — audit_logs is the
        // low-level compliance trail; loan_events is the investor-
        // visible lifecycle timeline).
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $originator = Originator::create(['name' => 'Audit-'.uniqid(), 'description' => 'x', 'buyback' => false]);
        $borrower = Borrower::factory()->create();
        $loan = Loan::factory()->create([
            'originator_id' => $originator->id,
            'borrower_id' => $borrower->id,
            'status' => 'draft',
        ]);

        $auditBefore = AuditLog::where('model_type', Loan::class)
            ->where('model_id', $loan->id)
            ->count();

        $loan->transitionTo(Loan::STATUS_PUBLISHED);

        $auditAfter = AuditLog::where('model_type', Loan::class)
            ->where('model_id', $loan->id)
            ->count();

        $this->assertGreaterThan($auditBefore, $auditAfter,
            'loan status transition must write at least one audit_logs row');
    }

    public function test_audit_log_redacts_sensitive_fields(): void
    {
        // Auditable's logAudit strips sensitive fields. Verify
        // explicitly — a User password change must NOT end up in
        // audit_logs clear-text.
        $user = User::factory()->create();
        $this->actingAs($user);

        $user->update(['password' => \Hash::make('new-password')]);

        $audit = AuditLog::where('model_type', User::class)
            ->where('model_id', $user->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        if (isset($audit->new_values['password'])) {
            $this->assertSame('[REDACTED]', $audit->new_values['password'],
                'Auditable must redact password field');
        }
    }

    // ──────────────────────────────────────────────────────────────
    // Negative coverage — documented gaps
    // ──────────────────────────────────────────────────────────────

    public function test_p3_f8_investment_service_transitions_do_no_t_emit_loan_event(): void
    {
        // **Documents finding P3-F8 (LOW).**
        //
        // InvestmentService auto-transitions published → funding (first
        // investment) and funding → funded (fully funded). These
        // transitions do NOT emit a loan_events row — only the Auditable
        // trait's audit_logs row is written.
        //
        // Impact: the investor-visible lifecycle timeline
        // (/api/loans/{id}/events, built from loan_events) does not
        // show "funding started" or "fully funded" events. Investors
        // must infer these from other signals (funded_amount reaching
        // loan.amount). LOW severity — no data loss, just completeness
        // gap in investor UX.
        //
        // Fix (v1.1 candidate): emit LoanEvent(status_changed, ...)
        // when InvestmentService calls transitionTo. ~30 min per
        // transition; 2 call sites total.
        //
        // PARTIALLY CLOSED 2026-08-13. Removing the «Активирай» button
        // (client decision) made funding → active a SYSTEM-driven
        // transition, and the Auditable fallback is wrong for it: the
        // audit_logs row would be attributed to whichever INVESTOR
        // happened to place the closing investment, as if they had
        // activated the loan. So that one transition now emits the
        // LoanEvent this finding asked for.
        //
        // Still open: published → funding and funding → funded remain
        // silent, so the investor timeline still cannot show "funding
        // started" / "fully funded".
        $loan = $this->makeDraftLoan();
        $loan->transitionTo(Loan::STATUS_PUBLISHED);
        $investor = $this->makeKycdInvestor();

        $eventsBefore = LoanEvent::where('loan_id', $loan->id)->count();

        app(InvestmentService::class)->invest(
            $investor, $loan->fresh(), '1000.00', (string) Str::uuid(),
        );

        // published → funding → funded → active (3 transitions).
        $loan->refresh();
        $this->assertSame(Loan::STATUS_ACTIVE, $loan->status,
            'sanity: full funding now activates the loan on its own');

        $events = LoanEvent::where('loan_id', $loan->id)
            ->where('id', '>', 0)
            ->orderBy('id')
            ->get();

        // EXACTLY one event, for the activation only — the two funding
        // bookkeeping transitions still write nothing.
        $this->assertCount($eventsBefore + 1, $events,
            'P3-F8: only the auto-activation emits a loan_event; the funding transitions still do not');

        $activation = $events->last();
        $this->assertSame(LoanEvent::TYPE_STATUS_CHANGED, $activation->event_type);
        $this->assertSame(Loan::STATUS_FUNDED, $activation->from_status);
        $this->assertSame(Loan::STATUS_ACTIVE, $activation->to_status);
        $this->assertSame(LoanEvent::TRIGGERED_BY_SYSTEM, $activation->triggered_by,
            'the platform activated this, not the investor who happened to close the funding');
        $this->assertNull($activation->triggered_by_user_id);
    }

    public function test_p3_f9_filament_admin_transitions_do_no_t_emit_loan_event(): void
    {
        // **Documents finding P3-F9 (LOW).**
        //
        // Filament LoanResource actions (publish, unpublish) call
        // transitionTo() directly but do NOT write a loan_events row.
        // Only Auditable's audit_logs is written (admin-only
        // visibility). Investor timeline does not show when a loan was
        // published. (The «Активирай» action this finding also covered
        // no longer exists — activation moved into InvestmentService
        // on 2026-08-13 and DOES emit a system event; see P3-F8.)
        //
        // Fix (v1.1 candidate): emit LoanEvent at each Filament action.
        // Admin as triggered_by_user_id — shows in the admin-internal
        // audit trail; investor-visible timeline would see
        // triggered_by=admin with no user identity. Same ~30 min per
        // action.
        //
        // Documents the gap; future v1.1 closure flips assertion.
        $loan = $this->makeDraftLoan();

        $eventsBefore = LoanEvent::where('loan_id', $loan->id)->count();
        $loan->transitionTo(Loan::STATUS_PUBLISHED);
        $eventsAfter = LoanEvent::where('loan_id', $loan->id)->count();

        $this->assertSame($eventsBefore, $eventsAfter,
            'P3-F9: draft → published transition writes no loan_events (documented gap)');
    }

    // ──────────────────────────────────────────────────────────────
    // Immutability verification — defense-in-depth
    // ──────────────────────────────────────────────────────────────

    public function test_audit_log_rows_are_append_only_at_model_layer(): void
    {
        // AuditLog must never be mutated. Verify at the model layer —
        // the DB immutability triggers are separately tested in F1's
        // suite; here we confirm the model doesn't expose an
        // update path that bypasses them.
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->update(['name' => 'Test']);

        $audit = AuditLog::latest('id')->first();
        $this->assertNotNull($audit);

        // Audit 2026-09-01: the old catch-all accepted ANY exception (a typo in
        // the test would have passed). AuditLog has no app-level guard, so the
        // MySQL trigger IS the control — demand it by name, then prove the row.
        try {
            $audit->update(['user_id' => 999]);
            $this->fail('audit_logs UPDATE must be rejected by the immutability trigger');
        } catch (QueryException $e) {
            $this->assertStringContainsString('45000', $e->getMessage(), 'rejection must come from the SIGNAL trigger, not another SQL error');
        }
        $this->assertSame($user->id, $audit->fresh()->user_id, 'audit_log user_id must not be modifiable');
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function makeDraftLoan(): Loan
    {
        $originator = Originator::create(['name' => 'AT-'.uniqid(), 'description' => 'x', 'buyback' => false]);
        $borrower = Borrower::factory()->create();

        return Loan::factory()->create([
            'originator_id' => $originator->id,
            'borrower_id' => $borrower->id,
            'status' => 'draft',
            'amount' => '1000.00',
            'funded_amount' => 0,
            'interest_rate' => '10.00',
            'interest_rate_annual' => '12.00',
            'term_months' => 6,
        ]);
    }

    private function makeKycdInvestor(): User
    {
        static $counter = 0;
        $counter++;
        $u = User::factory()->kycApproved()->create([
            'email' => "at-{$counter}-".uniqid().'@test.local',
        ]);
        $wallet = Wallet::firstOrCreate(['user_id' => $u->id]);
        $wallet->forceFill([
            'available' => '10000.00', 'invested' => 0, 'earned' => 0, 'reserved' => 0,
        ])->save();

        return $u->fresh();
    }
}
