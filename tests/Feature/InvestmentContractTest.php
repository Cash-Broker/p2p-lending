<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\Investment;
use App\Models\InvestmentContract;
use App\Models\LegalEntityProfile;
use App\Models\Loan;
use App\Models\User;
use App\Services\InvestmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Per-investment loan agreement («Договор за целеви паричен заем»):
 * frozen snapshot created atomically with the investment, click-wrap
 * acceptance evidence (no signatures — client decision 2026-08-09),
 * on-demand PDF rendering, and the access rules around it.
 */
class InvestmentContractTest extends TestCase
{
    use RefreshDatabase;

    private function investor(string $available = '5000.00'): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => $available])->save();

        return $user;
    }

    private function offerId(Loan $loan, PayoutType $type): int
    {
        return $loan->offers()->where('payout_type', $type)->value('id');
    }

    private function invest(User $user, Loan $loan, int $offerId, string $amount = '1000'): Investment
    {
        $response = $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
            'amount' => $amount,
            'loan_offer_id' => $offerId,
        ], ['X-Idempotency-Key' => 'ct-'.uniqid()])->assertStatus(201);

        return Investment::findOrFail($response->json('investment.id'));
    }

    public function test_invest_creates_contract_with_frozen_snapshot(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 12]);
        $user = $this->investor();

        $investment = $this->invest($user, $loan, $this->offerId($loan, PayoutType::InterestOnly));

        $contract = $investment->contract;
        $this->assertNotNull($contract, 'Investing must conclude a contract atomically.');
        $this->assertSame($user->id, $contract->user_id);
        $this->assertSame($loan->id, $contract->loan_id);
        $this->assertSame('v1', $contract->template_version);
        $this->assertNotNull($contract->accepted_at);
        $this->assertNotNull($contract->ip_address);

        $party = $contract->party_snapshot;
        $this->assertSame('individual', $party['account_type']);
        $this->assertSame($user->name, $party['name']);
        // Individuals: no ЕГН/адрес — neither collected nor printed
        // (client decision 2026-08-09); identification is name + email.
        $this->assertNull($party['identifier_label']);
        $this->assertNull($party['identifier']);
        $this->assertNull($party['address']);
        $this->assertSame($user->email, $party['email']);

        $terms = $contract->terms_snapshot;
        $this->assertSame('1000.00', $terms['amount']);
        $this->assertSame('хиляда евро', $terms['amount_words']);
        $this->assertSame('16.00', $terms['interest_rate']);
        $this->assertSame('шестнадесет', $terms['interest_rate_words']);
        $this->assertSame(12, $terms['term_months']);
        $this->assertSame('дванадесет', $terms['term_words']);
        $this->assertSame('interest_only', $terms['payout_type']);
        $this->assertCount(12, $terms['schedule']);
        $this->assertSame('1000.00', $terms['total_principal']);
        $this->assertSame('ВАМА АСЕТ ЕООД', $terms['company_name']);
        $this->assertSame('201035515', $terms['company_eik']);
        $this->assertSame('Пловдив', $terms['city']);
    }

    public function test_capitalized_contract_snapshots_single_maturity_installment(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 12]);
        $user = $this->investor();

        $investment = $this->invest($user, $loan, $this->offerId($loan, PayoutType::Capitalized));

        $terms = $investment->contract->terms_snapshot;
        $this->assertSame('capitalized', $terms['payout_type']);
        $this->assertCount(1, $terms['schedule']);
        $this->assertSame('1000.00', $terms['schedule'][0]['principal']);
        $this->assertSame($terms['total_repaid'], $terms['schedule'][0]['total']);
    }

    public function test_idempotent_retry_creates_single_contract(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $user = $this->investor();
        $offerId = $this->offerId($loan, PayoutType::Amortizing);
        $key = 'contract-idem-key';

        foreach (range(1, 2) as $attempt) {
            $this->actingAs($user)->postJson("/api/loans/{$loan->id}/invest", [
                'amount' => 100,
                'loan_offer_id' => $offerId,
            ], ['X-Idempotency-Key' => $key])->assertStatus(201);
        }

        $this->assertSame(1, Investment::where('user_id', $user->id)->count());
        $this->assertSame(1, InvestmentContract::where('user_id', $user->id)->count());
    }

    public function test_legacy_no_offer_investment_creates_no_contract(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $user = $this->investor();

        // Legacy path — reachable only outside HTTP (loan_offer_id is
        // required by InvestRequest).
        $investment = app(InvestmentService::class)->invest($user, $loan, '100.00', 'legacy-'.uniqid());

        $this->assertNull($investment->contract);
    }

    public function test_legal_entity_party_snapshot_carries_company_identification(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $user = $this->investor();
        $user->forceFill(['account_type' => User::TYPE_LEGAL_ENTITY])->save();
        LegalEntityProfile::create([
            'user_id' => $user->id,
            'legal_name' => 'Инвест Къмпани ЕООД',
            'eik' => '123456789',
            'address_country' => 'BG',
            'address_city' => 'София',
            'address_postcode' => '1000',
            'address_street' => 'ул. Тестова 1',
            'representative_role' => 'upravitel',
        ]);

        $investment = $this->invest($user->fresh(), $loan, $this->offerId($loan, PayoutType::Amortizing));

        $party = $investment->contract->party_snapshot;
        $this->assertSame('legal_entity', $party['account_type']);
        $this->assertSame('Инвест Къмпани ЕООД', $party['name']);
        $this->assertSame('ЕИК', $party['identifier_label']);
        $this->assertSame('123456789', $party['identifier']);
        $this->assertSame('гр. София 1000, ул. Тестова 1', $party['address']);
        $this->assertSame($user->name, $party['representative']);
        $this->assertSame('Управител', $party['representative_role']);
    }

    public function test_snapshot_is_immune_to_later_offer_edits(): void
    {
        $loan = Loan::factory()->funding()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $user = $this->investor();
        $offer = $loan->offers()->where('payout_type', PayoutType::Amortizing)->first();

        $investment = $this->invest($user, $loan, $offer->id);

        // Offers stay editable while the loan is funding.
        $offer->update(['interest_rate' => '13.00']);

        $terms = $investment->contract->fresh()->terms_snapshot;
        $this->assertSame('12.00', $terms['interest_rate']);
        $this->assertSame('дванадесет', $terms['interest_rate_words']);
    }

    public function test_owner_downloads_contract_pdf(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $user = $this->investor();
        $investment = $this->invest($user, $loan, $this->offerId($loan, PayoutType::InterestOnly));

        $response = $this->actingAs($user)->get("/api/investments/{$investment->id}/contract");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_contract_download_denied_for_other_users_and_guests(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $owner = $this->investor();
        $investment = $this->invest($owner, $loan, $this->offerId($loan, PayoutType::InterestOnly));

        // 404, not 403 — no existence oracle over sequential investment ids.
        $stranger = $this->investor();
        $this->actingAs($stranger)->getJson("/api/investments/{$investment->id}/contract")->assertStatus(404);

        // Fresh unauthenticated request (flush the actingAs session).
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/investments/{$investment->id}/contract")->assertStatus(401);
    }

    public function test_download_returns_404_for_investment_without_contract(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $user = $this->investor();
        $investment = app(InvestmentService::class)->invest($user, $loan, '100.00', 'legacy404-'.uniqid());

        $this->actingAs($user)->getJson("/api/investments/{$investment->id}/contract")->assertStatus(404);
    }

    public function test_preview_renders_draft_pdf_before_investing(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $user = $this->investor();
        $offerId = $this->offerId($loan, PayoutType::Capitalized);

        $response = $this->actingAs($user)
            ->get("/api/loans/{$loan->id}/contract-preview?amount=250.50&loan_offer_id={$offerId}");

        $response->assertStatus(200)->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        // A preview must never mint acceptance evidence.
        $this->assertSame(0, InvestmentContract::count());
    }

    public function test_preview_rejects_foreign_or_disabled_offer(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $otherLoan = Loan::factory()->published()->create(['amount' => 10000, 'term_months' => 6]);
        $user = $this->investor();

        $foreignOffer = $otherLoan->offers()->value('id');
        $this->actingAs($user)
            ->getJson("/api/loans/{$loan->id}/contract-preview?amount=100&loan_offer_id={$foreignOffer}")
            ->assertStatus(422);

        $disabled = $loan->offers()->where('payout_type', PayoutType::InterestOnly)->first();
        $disabled->update(['is_enabled' => false]);
        $this->actingAs($user)
            ->getJson("/api/loans/{$loan->id}/contract-preview?amount=100&loan_offer_id={$disabled->id}")
            ->assertStatus(422);
    }

    public function test_preview_rejects_non_fundable_loan(): void
    {
        // No amount override — repaid() derives funded_amount from the
        // definition amount and the loans CHECK requires them consistent.
        $loan = Loan::factory()->repaid()->create(['term_months' => 6]);
        $user = $this->investor();
        $offerId = $loan->offers()->value('id');

        $this->actingAs($user)
            ->getJson("/api/loans/{$loan->id}/contract-preview?amount=100&loan_offer_id={$offerId}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('loan');
    }

    public function test_contract_rows_resist_quiet_and_forced_writes(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $user = $this->investor();
        $investment = $this->invest($user, $loan, $this->offerId($loan, PayoutType::Amortizing));
        $contract = $investment->contract;

        try {
            $contract->forceFill(['ip_address' => '10.0.0.1'])->saveQuietly();
            $this->fail('saveQuietly() must not mutate acceptance evidence.');
        } catch (LogicException) {
        }

        $this->assertSame(
            $contract->getOriginal('ip_address'),
            $contract->fresh()->ip_address,
            'The evidence row must be untouched after the blocked write.',
        );
    }

    public function test_admin_route_serves_contract_and_blocks_non_admins(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $user = $this->investor();
        $investment = $this->invest($user, $loan, $this->offerId($loan, PayoutType::Amortizing));

        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $response = $this->actingAs($admin)->get("/admin/investment-contract/{$investment->id}");
        $response->assertStatus(200)->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->actingAs($user)->get("/admin/investment-contract/{$investment->id}")->assertStatus(403);
    }

    public function test_contract_rows_are_immutable(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $user = $this->investor();
        $investment = $this->invest($user, $loan, $this->offerId($loan, PayoutType::Amortizing));

        $this->expectException(LogicException::class);
        $investment->contract->update(['ip_address' => '10.0.0.1']);
    }

    public function test_portfolio_flags_contract_presence(): void
    {
        $loan = Loan::factory()->published()->create(['amount' => 10000, 'funded_amount' => 0, 'term_months' => 6]);
        $user = $this->investor();
        $this->invest($user, $loan, $this->offerId($loan, PayoutType::Amortizing));

        $this->actingAs($user)->getJson('/api/portfolio')
            ->assertStatus(200)
            ->assertJsonPath('data.0.has_contract', true);
    }
}
