<?php

namespace Tests\Audit;

use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 Step 2 — Authorization boundaries audit.
 *
 * Verifies:
 *   - Cross-user data access prevention (investor A can't fetch
 *     investor B's private rows via API).
 *   - Borrower PII containment (LoanResource API never exposes
 *     borrower_id, personal_id, address, phone).
 *   - Public endpoint shape (no PII / financial leakage on
 *     unauthenticated endpoints).
 *   - LoanPolicy::viewEvents (investor without position denied).
 *   - DEFAULT-status loan visibility for holding investors (P3-F4
 *     candidate — test asserts current behaviour; if this test
 *     SHOULD pass, `INVESTOR_VISIBLE_STATUSES` is missing
 *     `STATUS_DEFAULT`).
 *   - Filament admin panel gated to admins only.
 */
class Phase3AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────
    // Cross-user data access
    // ──────────────────────────────────────────────────────────────

    public function test_investor_A_cannot_view_investor_B_investment(): void
    {
        $a = $this->makeInvestor();
        $b = $this->makeInvestor();
        $loan = $this->makePublishedLoan();
        $investment = Investment::create([
            'user_id' => $b->id,
            'loan_id' => $loan->id,
            'amount' => '100.00',
            'invested_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->actingAs($a);

        // Portfolio returns ONLY own investments — scoped by user_id.
        $resp = $this->getJson('/api/portfolio');
        $resp->assertOk();
        $ids = collect($resp->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($investment->id, $ids,
            'investor A must not see investor B\'s investment in /api/portfolio');
    }

    public function test_investor_A_cannot_view_investor_B_withdrawal(): void
    {
        $a = $this->makeInvestor();
        $b = $this->makeInvestor();
        $bWithdrawal = WithdrawalRequest::create([
            'user_id' => $b->id,
            'amount' => '100.00',
            'iban' => 'BG80BNBG96611020345678',
            'status' => 'pending',
            'ip_address' => '127.0.0.1',
        ]);

        $this->actingAs($a);
        $resp = $this->getJson('/api/withdrawal/history');
        $resp->assertOk();
        $ids = collect($resp->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($bWithdrawal->id, $ids,
            'investor A must not see investor B\'s withdrawal requests');
    }

    public function test_investor_A_cannot_view_investor_B_transactions(): void
    {
        $a = $this->makeInvestor();
        $b = $this->makeInvestor();
        $bTxn = Transaction::create([
            'user_id' => $b->id,
            'type' => 'deposit',
            'amount' => '500.00',
            'description' => 'B\'s deposit',
            'ip_address' => '127.0.0.1',
        ]);

        $this->actingAs($a);
        $resp = $this->getJson('/api/transactions');
        $resp->assertOk();
        $ids = collect($resp->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($bTxn->id, $ids,
            'investor A must not see investor B\'s transactions');
    }

    // ──────────────────────────────────────────────────────────────
    // LoanPolicy::viewEvents
    // ──────────────────────────────────────────────────────────────

    public function test_investor_without_position_denied_on_loan_events(): void
    {
        $outsider = $this->makeInvestor();
        $loan = $this->makePublishedLoan();

        $this->actingAs($outsider);
        $resp = $this->getJson("/api/loans/{$loan->id}/events");
        $resp->assertForbidden();
    }

    public function test_investor_with_position_can_access_loan_events(): void
    {
        $insider = $this->makeInvestor();
        $loan = $this->makePublishedLoan();
        Investment::create([
            'user_id' => $insider->id,
            'loan_id' => $loan->id,
            'amount' => '100.00',
            'invested_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->actingAs($insider);
        $resp = $this->getJson("/api/loans/{$loan->id}/events");
        $resp->assertOk();
    }

    // ──────────────────────────────────────────────────────────────
    // INVESTOR_VISIBLE_STATUSES — P3-F4 candidate
    // ──────────────────────────────────────────────────────────────

    public function test_investor_with_position_can_view_DEFAULT_loan_details(): void
    {
        // P3-F4 fix: `INVESTOR_VISIBLE_STATUSES` now includes
        // `STATUS_DEFAULT`. An investor holding a position in a
        // DEFAULT loan must be able to open the loan detail page —
        // they have money at stake and /api/portfolio already
        // surfaces the loan in their list.
        //
        // Additionally verifies the loan appears in /api/portfolio
        // so the two surfaces stay consistent.
        $insider = $this->makeInvestor();
        $loan = $this->makePublishedLoan();
        Investment::create([
            'user_id' => $insider->id,
            'loan_id' => $loan->id,
            'amount' => '100.00',
            'invested_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        // Move loan through allowed transitions to DEFAULT. Use raw
        // SQL for the final step since `late → default` is admin-only
        // and doesn't matter for the policy test.
        DB::table('loans')->where('id', $loan->id)->update(['status' => Loan::STATUS_DEFAULT]);

        $this->actingAs($insider);

        // Detail page accessible.
        $detailResp = $this->getJson("/api/loans/{$loan->id}");
        $detailResp->assertOk()
            ->assertJsonPath('status', Loan::STATUS_DEFAULT);

        // Portfolio list includes the loan — consistency check.
        $portfolioResp = $this->getJson('/api/portfolio');
        $portfolioResp->assertOk();
        $loanIds = collect($portfolioResp->json('data'))
            ->pluck('loan.id')
            ->toArray();
        $this->assertContains($loan->id, $loanIds,
            'DEFAULT loan must appear in investor portfolio list');
    }

    // ──────────────────────────────────────────────────────────────
    // Borrower PII containment
    // ──────────────────────────────────────────────────────────────

    public function test_loan_show_api_does_not_expose_borrower_pii(): void
    {
        $investor = $this->makeInvestor();
        $loan = $this->makePublishedLoan();

        $this->actingAs($investor);
        $resp = $this->getJson("/api/loans/{$loan->id}");
        $resp->assertOk();

        $body = $resp->content();
        // Hard-fail on any sensitive Borrower field leak.
        $this->assertStringNotContainsString('"borrower_id"', $body);
        $this->assertStringNotContainsString('"personal_id"', $body);
        $this->assertStringNotContainsString('"address"', $body);
        $this->assertStringNotContainsString('"phone"', $body);
        // Full_name from Borrower (non-anonymized).
        $this->assertStringNotContainsString('"full_name"', $body);
    }

    public function test_loans_index_api_does_not_expose_borrower_pii(): void
    {
        $investor = $this->makeInvestor();
        $this->makePublishedLoan();

        $this->actingAs($investor);
        $resp = $this->getJson('/api/loans');
        $resp->assertOk();

        $body = $resp->content();
        $this->assertStringNotContainsString('"borrower_id"', $body);
        $this->assertStringNotContainsString('"personal_id"', $body);
        $this->assertStringNotContainsString('"address"', $body);
        $this->assertStringNotContainsString('"phone"', $body);
    }

    // ──────────────────────────────────────────────────────────────
    // Public endpoints — shape / no leakage
    // ──────────────────────────────────────────────────────────────

    public function test_fees_config_public_endpoint_shape_stable(): void
    {
        $resp = $this->getJson('/api/fees/config');
        $resp->assertOk()
            ->assertJsonStructure(['withdrawal' => ['enabled', 'amount']]);
        // No extra top-level keys that could leak admin/financial data.
        $data = $resp->json();
        $this->assertSame(['withdrawal'], array_keys($data));
    }

    public function test_health_scheduler_public_endpoint_has_no_pii(): void
    {
        $resp = $this->getJson('/api/health/scheduler');
        // 200 healthy/warning OR 503 critical — either is valid;
        // in a fresh test DB with no cron runs, status is 'critical'.
        $this->assertContains($resp->status(), [200, 503],
            "unexpected health endpoint status: {$resp->status()}");

        $body = $resp->content();
        // Must not leak PII / auth data through the public endpoint.
        $this->assertStringNotContainsString('"email"', strtolower($body));
        $this->assertStringNotContainsString('"password"', strtolower($body));
        $this->assertStringNotContainsString('personal_id', $body);
    }

    // ──────────────────────────────────────────────────────────────
    // Admin panel gating
    // ──────────────────────────────────────────────────────────────

    public function test_investor_cannot_access_filament_admin_panel(): void
    {
        $investor = $this->makeInvestor();

        $this->actingAs($investor);
        $resp = $this->get('/admin');
        // Filament refuses via canAccessPanel() on FilamentUser contract.
        $this->assertNotEquals(200, $resp->status(),
            'investor must NOT reach the admin panel (User::canAccessPanel returns true only for role=admin)');
    }

    public function test_admin_can_access_filament_admin_panel(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->email_verified_at = now();
        $admin->save();

        $this->actingAs($admin);
        $resp = $this->get('/admin');
        // Either 200 (direct), or 302 to a login-state landing.
        $this->assertLessThan(500, $resp->status());
        $this->assertNotEquals(403, $resp->status());
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function makeInvestor(): User
    {
        static $counter = 0;
        $counter++;
        $u = User::factory()->kycApproved()->create([
            'email' => "audit-auth-inv-{$counter}-" . uniqid() . "@test.local",
        ]);
        Wallet::firstOrCreate(['user_id' => $u->id], [
            'available' => '10000.00', 'invested' => 0, 'earned' => 0, 'reserved' => 0,
        ]);
        return $u;
    }

    private function makePublishedLoan(): Loan
    {
        $originator = Originator::create([
            'name' => 'Auth-Audit-' . uniqid(),
            'description' => 'Phase 3 authorization audit fixture',
            'buyback' => true,
        ]);
        $borrower = Borrower::factory()->create();
        $borrower->anonymizedProfile()->create([
            'risk_class' => 'A',
            'region' => 'София',
            'loan_purpose' => 'Тест',
            'age_group' => '26-35',
        ]);
        return Loan::factory()->create([
            'originator_id' => $originator->id,
            'borrower_id' => $borrower->id,
            'status' => 'published',
            'published_at' => now(),
            'amount' => '1000.00',
            'funded_amount' => '0.00',
            'interest_rate' => '10.00',
            'interest_rate_annual' => '12.00',
            'term_months' => 6,
        ]);
    }
}
