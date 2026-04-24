<?php

namespace Tests\Audit;

use App\Models\Loan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 3 — Loan state machine integrity audit.
 *
 * Exhaustively verifies `Loan::ALLOWED_TRANSITIONS` across all 9×8 = 72
 * non-self transitions:
 *   - 13 allowed transitions succeed AND persist the new status.
 *   - 59 forbidden transitions throw InvalidArgumentException AND leave
 *     status unchanged.
 *
 * Also covers:
 *   - Terminal states (`repaid`, `bought_back`) reject every transition.
 *   - Mass-assignment via fill() hits the `booted()` updating hook.
 *   - Direct property assignment hits the same hook.
 *   - Raw `DB::update()` does NOT hit the hook — documents finding P3-F3
 *     (known limitation; app-level event-driven guard only).
 *
 * Opt-in via `php artisan test testsuite=Audit`. See
 * AUDIT_REPORT_PHASE3.md §1.
 */
class Phase3StateMachineMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const STATUSES = [
        Loan::STATUS_DRAFT,
        Loan::STATUS_PUBLISHED,
        Loan::STATUS_FUNDING,
        Loan::STATUS_FUNDED,
        Loan::STATUS_ACTIVE,
        Loan::STATUS_LATE,
        Loan::STATUS_DEFAULT,
        Loan::STATUS_REPAID,
        Loan::STATUS_BOUGHT_BACK,
    ];

    public static function transitionMatrixProvider(): array
    {
        $allowed = Loan::ALLOWED_TRANSITIONS;
        $statuses = [
            'draft', 'published', 'funding', 'funded', 'active',
            'late', 'default', 'repaid', 'bought_back',
        ];

        $cases = [];
        foreach ($statuses as $from) {
            foreach ($statuses as $to) {
                if ($from === $to) {
                    continue; // self-transitions are not tested (Eloquent: no isDirty)
                }
                $shouldSucceed = in_array($to, $allowed[$from] ?? [], true);
                $cases["{$from}__to__{$to}"] = [$from, $to, $shouldSucceed];
            }
        }
        return $cases;
    }

    #[DataProvider('transitionMatrixProvider')]
    public function test_transition_matrix(string $from, string $to, bool $shouldSucceed): void
    {
        $loan = $this->makeDraftLoan();

        // Set the desired `from` state via raw SQL — bypasses the
        // `updating` event so we can stage any status, including ones
        // the model normally wouldn't reach from draft.
        DB::table('loans')->where('id', $loan->id)->update(['status' => $from]);
        $loan = $loan->fresh();
        $this->assertSame($from, $loan->status, 'setup: loan must be in `from` state');

        if ($shouldSucceed) {
            $loan->transitionTo($to);
            $this->assertSame($to, $loan->fresh()->status, "allowed transition {$from} → {$to} must persist");
        } else {
            try {
                $loan->transitionTo($to);
                $this->fail("forbidden transition {$from} → {$to} should have thrown");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($from, $e->getMessage());
                $this->assertStringContainsString($to, $e->getMessage());
            }
            // State unchanged — failed transition must not leak to DB.
            $this->assertSame($from, $loan->fresh()->status, "forbidden transition {$from} → {$to} must leave status unchanged");
        }
    }

    public function test_terminal_repaid_rejects_every_transition(): void
    {
        $loan = $this->makeDraftLoan();
        DB::table('loans')->where('id', $loan->id)->update(['status' => Loan::STATUS_REPAID]);
        $loan = $loan->fresh();

        foreach (self::STATUSES as $target) {
            if ($target === Loan::STATUS_REPAID) {
                continue;
            }
            try {
                $loan->transitionTo($target);
                $this->fail("terminal `repaid` must reject transition to {$target}");
            } catch (\InvalidArgumentException $e) {
                // expected
            }
        }
        $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);
    }

    public function test_terminal_bought_back_rejects_every_transition(): void
    {
        $loan = $this->makeDraftLoan();
        DB::table('loans')->where('id', $loan->id)->update(['status' => Loan::STATUS_BOUGHT_BACK]);
        $loan = $loan->fresh();

        foreach (self::STATUSES as $target) {
            if ($target === Loan::STATUS_BOUGHT_BACK) {
                continue;
            }
            try {
                $loan->transitionTo($target);
                $this->fail("terminal `bought_back` must reject transition to {$target}");
            } catch (\InvalidArgumentException $e) {
                // expected
            }
        }
        $this->assertSame(Loan::STATUS_BOUGHT_BACK, $loan->fresh()->status);
    }

    public function test_mass_assignment_via_fill_caught_by_updating_hook(): void
    {
        // `status` is in $fillable. A `fill(['status' => 'active'])` on a
        // draft loan would be a `draft → active` transition, which is
        // forbidden. The updating hook MUST catch this path even though
        // it's not going through transitionTo().
        $loan = $this->makeDraftLoan();
        try {
            $loan->fill(['status' => Loan::STATUS_ACTIVE])->save();
            $this->fail('fill() with invalid status must hit the updating hook');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Invalid loan status transition', $e->getMessage());
        }
        $this->assertSame(Loan::STATUS_DRAFT, $loan->fresh()->status);
    }

    public function test_direct_property_assignment_caught_by_updating_hook(): void
    {
        $loan = $this->makeDraftLoan();
        $loan->status = Loan::STATUS_REPAID;
        try {
            $loan->save();
            $this->fail('direct property assignment with invalid status must hit the updating hook');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Invalid loan status transition', $e->getMessage());
        }
        $this->assertSame(Loan::STATUS_DRAFT, $loan->fresh()->status);
    }

    public function test_raw_db_update_bypasses_transition_guard_documents_known_gap(): void
    {
        // Documents P3-F3 (LOW): raw SQL bypasses Eloquent events.
        // This test ASSERTS the gap exists so a future commit that
        // closes the gap (DB CHECK, status_history table, etc.) forces
        // an explicit audit review — if this test ever starts failing,
        // the closure is a breaking change worth celebrating.
        $loan = $this->makeDraftLoan();
        DB::table('loans')->where('id', $loan->id)->update(['status' => Loan::STATUS_REPAID]);
        $this->assertSame(
            Loan::STATUS_REPAID,
            $loan->fresh()->status,
            'raw SQL UPDATE bypasses the updating hook (known gap, documented as P3-F3)',
        );
    }

    public function test_funded_to_active_generates_amortization_schedule(): void
    {
        // Side-effect contract test: transitioning funded → active via
        // transitionTo() MUST trigger AmortizationService::generateSchedule.
        $loan = $this->makeDraftLoan([
            'amount' => '1000.00',
            'interest_rate' => '12.00',
            'interest_rate_annual' => '15.00',
            'term_months' => 6,
        ]);
        DB::table('loans')->where('id', $loan->id)->update([
            'status' => Loan::STATUS_FUNDED,
            'funded_amount' => '1000.00',
        ]);
        $loan = $loan->fresh();

        $this->assertSame(0, $loan->amortizationSchedules()->count(), 'pre-condition: no schedule yet');

        $loan->transitionTo(Loan::STATUS_ACTIVE);

        $this->assertSame(6, $loan->amortizationSchedules()->count(), 'funded → active must generate 6 schedule rows');
        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status);
    }

    public function test_late_to_active_does_not_regenerate_schedule(): void
    {
        // `late → active` is a recovery transition. The schedule was
        // generated on the ORIGINAL funded → active activation and
        // must NOT be regenerated (would wipe paid statuses, due dates,
        // etc.). Regression guard for the conditional inside
        // transitionTo().
        $loan = $this->makeDraftLoan([
            'amount' => '1000.00',
            'interest_rate' => '12.00',
            'interest_rate_annual' => '15.00',
            'term_months' => 3,
        ]);
        // Walk through legitimate transitions to get a schedule.
        DB::table('loans')->where('id', $loan->id)->update([
            'status' => Loan::STATUS_FUNDED,
            'funded_amount' => '1000.00',
        ]);
        $loan->fresh()->transitionTo(Loan::STATUS_ACTIVE);
        $this->assertSame(3, $loan->fresh()->amortizationSchedules()->count());

        // Now force loan to late, then recover to active.
        DB::table('loans')->where('id', $loan->id)->update(['status' => Loan::STATUS_LATE]);
        $loan->fresh()->transitionTo(Loan::STATUS_ACTIVE);

        $this->assertSame(3, $loan->fresh()->amortizationSchedules()->count(),
            'late → active must NOT regenerate the schedule');
    }

    // ────────────────────────────────────────────────────────────────
    // P3-F2 — funding → draft abandon path
    // ────────────────────────────────────────────────────────────────

    public function test_funding_to_draft_allowed_when_funded_amount_zero(): void
    {
        // Abandon path: a loan that reached FUNDING but lost all its
        // investors (e.g. rejected withdrawal returned their funds)
        // can be reset to DRAFT by admin via the Filament unpublish
        // action. P3-F2 Option A fix.
        $loan = $this->makeDraftLoan();
        DB::table('loans')->where('id', $loan->id)->update([
            'status' => Loan::STATUS_FUNDING,
            'funded_amount' => '0.00',
        ]);
        $loan = $loan->fresh();

        $loan->transitionTo(Loan::STATUS_DRAFT);

        $this->assertSame(Loan::STATUS_DRAFT, $loan->fresh()->status);
    }

    public function test_funding_to_draft_blocked_when_funded_amount_positive(): void
    {
        // Guard protects partial investors from being stranded by an
        // admin-triggered abandon. The transition is STRUCTURALLY
        // allowed (ALLOWED_TRANSITIONS[funding] includes draft) but
        // the booted() updating hook throws LogicException when
        // funded_amount > 0 — defense-in-depth against both admin
        // error and future code paths that bypass the Filament
        // visibility check.
        $loan = $this->makeDraftLoan();
        DB::table('loans')->where('id', $loan->id)->update([
            'status' => Loan::STATUS_FUNDING,
            'funded_amount' => '500.00',
        ]);
        $loan = $loan->fresh();

        try {
            $loan->transitionTo(Loan::STATUS_DRAFT);
            $this->fail('funding → draft with funded_amount > 0 must throw');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('funded_amount', $e->getMessage());
            $this->assertStringContainsString('500.00', $e->getMessage());
        }

        // Status unchanged — save() rolled back.
        $this->assertSame(Loan::STATUS_FUNDING, $loan->fresh()->status);
    }

    // ────────────────────────────────────────────────────────────────

    private function makeDraftLoan(array $overrides = []): Loan
    {
        return Loan::factory()->create(array_merge([
            'status' => Loan::STATUS_DRAFT,
            'amount' => '1000.00',
            'funded_amount' => '0.00',
            'interest_rate' => '10.00',
            'interest_rate_annual' => '12.00',
            'term_months' => 6,
        ], $overrides));
    }
}
