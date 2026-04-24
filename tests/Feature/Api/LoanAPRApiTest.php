<?php

namespace Tests\Feature\Api;

use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F5 — Investor-facing LoanResource API contract for the `apr` field.
 *
 * If any of these fail, the SPA will silently lose the ГПР column.
 * Contract-guard coverage per the F5 audit — data contract is the only
 * guarantee we can give without Dusk.
 */
class LoanAPRApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvestor(): User
    {
        return User::factory()->kycApproved()->create();
    }

    private function makePublishedLoan(array $overrides = []): Loan
    {
        $originator = Originator::factory()->create();
        $borrower = Borrower::factory()->create();
        $borrower->anonymizedProfile()->create([
            'risk_class' => 'A',
            'region' => 'София',
            'loan_purpose' => 'Тест',
            'age_group' => '26-35',
        ]);
        return Loan::factory()->create(array_merge([
            'originator_id' => $originator->id,
            'borrower_id' => $borrower->id,
            'status' => 'published',
            'published_at' => now(),
        ], $overrides));
    }

    public function test_loans_index_includes_apr_field(): void
    {
        $loan = $this->makePublishedLoan(['interest_rate_annual' => '12.75']);
        $this->actingAs($this->makeInvestor());

        $response = $this->getJson('/api/loans');

        $response->assertOk();
        // Find our loan in the response regardless of other seeded rows.
        $apr = collect($response->json('data'))
            ->firstWhere('id', $loan->id)['apr'] ?? null;
        $this->assertSame('12.75', $apr);
    }

    public function test_loan_show_includes_apr_field(): void
    {
        $loan = $this->makePublishedLoan(['interest_rate_annual' => '14.50']);
        $this->actingAs($this->makeInvestor());

        $response = $this->getJson("/api/loans/{$loan->id}");

        // Show endpoint returns via response()->json(new LoanResource($loan))
        // which serialises the Resource's fields at the ROOT level
        // (JsonSerializable path, no `data` wrapping). Index endpoint
        // manually wraps under `data` — see test_loans_index_* above.
        $response->assertOk()
            ->assertJsonPath('apr', '14.50');
    }

    public function test_apr_is_null_when_underlying_rate_is_zero(): void
    {
        // Edge case: a loan whose interest_rate_annual got set to 0 by
        // direct DB intervention (bypassing the Filament form's
        // minValue(0.01) guard). API must return null — NOT "0.00" —
        // so Vue renders "—" instead of a misleading "0.00%".
        $loan = $this->makePublishedLoan();
        DB::table('loans')->where('id', $loan->id)->update(['interest_rate_annual' => 0]);
        $this->actingAs($this->makeInvestor());

        $response = $this->getJson("/api/loans/{$loan->id}");

        $response->assertOk()
            ->assertJsonPath('apr', null);
    }
}
