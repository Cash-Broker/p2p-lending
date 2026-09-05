<?php

namespace App\Services;

use App\Models\Investment;
use App\Models\InvestmentContract;
use App\Models\LegalEntityProfile;
use App\Models\Loan;
use App\Models\LoanOffer;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\BulgarianNumberWords;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * «Договор за целеви паричен заем» — generated for every offer-based
 * investment (client requirement, 2026-08-09).
 *
 * Two responsibilities:
 *
 *  1. createForInvestment() — build + persist the frozen contract snapshot
 *     INSIDE InvestmentService::invest()'s transaction. The snapshot
 *     freezes everything the document needs (party data with PII decrypted
 *     at build time, amount/rate «словом», the projected repayment
 *     schedule, the company-side details from platform settings), so the
 *     PDF re-renders identically forever regardless of later edits.
 *     The row itself is the click-wrap acceptance evidence: no signatures
 *     — the invest click is recorded as the investor's consent
 *     (accepted_at / ip_address / user_agent).
 *
 *  2. renderPdf() / renderPreviewPdf() — dompdf rendering on demand.
 *     Nothing is written to disk. Preview renders a watermarked «ПРОЕКТ»
 *     document from live data BEFORE investing, so the investor can read
 *     what the invest click will conclude.
 *
 * The repayment schedule in the contract annex is the OfferProjection at
 * acceptance time. Its dates are indicative: the definitive
 * investment_schedules rows are generated at loan activation (funded →
 * active) and the annex says so explicitly.
 */
class InvestmentContractService
{
    public function __construct(private OfferProjectionService $projection) {}

    /**
     * Persist the contract snapshot for a freshly created investment.
     * MUST be called inside the same DB transaction as the investment —
     * an accepted investment without its contract evidence must not exist.
     */
    public function createForInvestment(Investment $investment, User $user, Loan $loan, LoanOffer $offer): InvestmentContract
    {
        $acceptedAt = $investment->invested_at ?? now();

        return InvestmentContract::create([
            'investment_id' => $investment->id,
            'user_id' => $user->id,
            'loan_id' => $loan->id,
            'party_snapshot' => $this->buildPartySnapshot($user),
            'terms_snapshot' => $this->buildTermsSnapshot($loan, $offer, (string) $investment->amount, $acceptedAt),
            'template_version' => InvestmentContract::TEMPLATE_VERSION_V1,
            'template_hash' => self::templateHash(InvestmentContract::TEMPLATE_VERSION_V1),
            'accepted_at' => $acceptedAt,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }

    /**
     * Render the concluded contract from its frozen snapshot.
     */
    public function renderPdf(InvestmentContract $contract): string
    {
        $this->assertTemplateUnchanged($contract);

        return $this->pdf($contract->template_version, [
            'party' => $contract->party_snapshot,
            'terms' => $contract->terms_snapshot,
            'acceptance' => [
                'is_preview' => false,
                'accepted_at' => $contract->accepted_at,
                'ip_address' => $contract->ip_address,
                'investment_id' => $contract->investment_id,
            ],
        ]);
    }

    /**
     * Render a watermarked draft from LIVE data — what the contract will
     * say if the investor commits to this offer with this amount now.
     */
    public function renderPreviewPdf(User $user, Loan $loan, LoanOffer $offer, string $amount): string
    {
        return $this->pdf(InvestmentContract::TEMPLATE_VERSION_V1, [
            'party' => $this->buildPartySnapshot($user),
            'terms' => $this->buildTermsSnapshot($loan, $offer, $amount, now()),
            'acceptance' => [
                'is_preview' => true,
                'accepted_at' => null,
                'ip_address' => null,
                'investment_id' => null,
            ],
        ]);
    }

    /**
     * Lender-party identification, frozen decrypted. Individuals are
     * identified by name + platform profile only — ЕГН/адрес are neither
     * collected nor printed (client decision 2026-08-09: «засега без»;
     * legal entities keep ЕИК + seat from the company profile).
     */
    private function buildPartySnapshot(User $user): array
    {
        $profile = $user->isLegalEntity() ? $user->legalEntityProfile : null;

        if ($profile !== null) {
            $roleLabel = LegalEntityProfile::REPRESENTATIVE_ROLES[$profile->representative_role] ?? null;

            return [
                'account_type' => User::TYPE_LEGAL_ENTITY,
                'name' => $profile->legal_name,
                'identifier_label' => 'ЕИК',
                'identifier' => $profile->eik,
                'address' => $this->composeLegalAddress($profile),
                'representative' => $user->name,
                'representative_role' => $roleLabel,
                'email' => $user->email,
            ];
        }

        return [
            'account_type' => User::TYPE_INDIVIDUAL,
            'name' => $user->name,
            'identifier_label' => null,
            'identifier' => null,
            'address' => null,
            'representative' => null,
            'representative_role' => null,
            'email' => $user->email,
        ];
    }

    /**
     * Commercial terms + the projected repayment schedule annex + the
     * company (ЗАЕМАТЕЛ) block from platform settings — all frozen.
     */
    private function buildTermsSnapshot(Loan $loan, LoanOffer $offer, string $amount, CarbonInterface $date): array
    {
        $rate = (string) $offer->interest_rate;
        $type = $offer->payout_type;
        $term = (int) $loan->term_months;

        $rows = $this->projection->schedule($amount, $rate, $term, $type);
        $summary = $this->projection->summary($amount, $rate, $term, $type);

        // The ЗАЕМАТЕЛ identity is a legally essential term. A blanked
        // setting must not silently freeze into concluded contracts —
        // warn loudly (but don't block investing over a settings mishap).
        foreach (['contract_company_name', 'contract_company_eik'] as $key) {
            if (trim((string) PlatformSetting::get($key, '')) === '') {
                Log::warning('Investment contract concluded with blank company requisite', [
                    'setting' => $key,
                    'loan_id' => $loan->id,
                ]);
            }
        }

        return [
            'contract_date' => $date->toDateString(),
            'city' => (string) PlatformSetting::get('contract_city', ''),
            'company_name' => (string) PlatformSetting::get('contract_company_name', ''),
            'company_eik' => (string) PlatformSetting::get('contract_company_eik', ''),
            'company_address' => (string) PlatformSetting::get('contract_company_address', ''),
            'company_manager' => (string) PlatformSetting::get('contract_company_manager', ''),
            'amount' => $amount,
            'amount_words' => BulgarianNumberWords::euroAmount($amount),
            'currency' => 'евро',
            'interest_rate' => $rate,
            'interest_rate_words' => BulgarianNumberWords::percent($rate),
            'term_months' => $term,
            'term_words' => BulgarianNumberWords::cardinal((string) $term, BulgarianNumberWords::GENDER_MASCULINE),
            'payout_type' => $type->value,
            'payout_label' => $type->label(),
            'loan_id' => $loan->id,
            'schedule' => array_map(fn (array $row) => [
                'due_date' => $row['due_date']->toDateString(),
                'principal' => $row['principal'],
                'interest' => $row['interest'],
                'total' => $row['total'],
            ], $rows),
            'total_principal' => $summary['total_principal'],
            'total_interest' => $summary['total_interest'],
            'total_repaid' => $summary['total_repaid'],
        ];
    }

    private function composeLegalAddress(LegalEntityProfile $profile): ?string
    {
        $cityLine = trim(implode(' ', array_filter([
            $profile->address_city !== null ? 'гр. '.$profile->address_city : null,
            $profile->address_postcode,
        ])));

        $parts = array_filter([
            $cityLine !== '' ? $cityLine : null,
            $profile->address_street,
            ($profile->address_country !== null && $profile->address_country !== 'BG')
                ? $profile->address_country
                : null,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * sha256 of the Blade template file a contract was concluded under.
     *
     * The snapshot freezes the DATA of the agreement; the wording lives in the
     * template file, pinned by `template_version` only by convention («wording
     * changes ⇒ new v2 file, never edit v1»). Storing the hash makes a silent
     * edit of v1 detectable — {@see assertTemplateUnchanged()}.
     */
    public static function templateHash(string $templateVersion): string
    {
        return hash_file('sha256', self::templatePath($templateVersion));
    }

    /**
     * Refuse to render a concluded contract with wording that differs from the
     * wording the investor accepted. Contracts concluded before the hash was
     * recorded (null) render unchecked — there is nothing to compare against
     * and evidence is never fabricated retroactively.
     */
    private function assertTemplateUnchanged(InvestmentContract $contract): void
    {
        if ($contract->template_hash === null) {
            return;
        }

        if (! hash_equals($contract->template_hash, self::templateHash($contract->template_version))) {
            throw new LogicException(sprintf(
                'Contract template %s was modified after contract #%d was concluded. Wording changes require a new template version; restore the original file.',
                $contract->template_version,
                $contract->id,
            ));
        }
    }

    private static function templatePath(string $templateVersion): string
    {
        return match ($templateVersion) {
            InvestmentContract::TEMPLATE_VERSION_V1 => resource_path('views/contracts/investment-v1.blade.php'),
            default => throw new LogicException("Unknown contract template version: {$templateVersion}"),
        };
    }

    private function pdf(string $templateVersion, array $data): string
    {
        $view = match ($templateVersion) {
            InvestmentContract::TEMPLATE_VERSION_V1 => 'contracts.investment-v1',
            default => throw new LogicException("Unknown contract template version: {$templateVersion}"),
        };

        // Defense in depth: no remote asset fetching, no embedded PHP —
        // the template is fully self-contained (bundled DejaVu fonts
        // carry Cyrillic). Blade escaping keeps user-entered names inert.
        return Pdf::loadView($view, $data)
            ->setPaper('a4')
            ->setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
            ->output();
    }
}
