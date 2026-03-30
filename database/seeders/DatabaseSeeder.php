<?php

namespace Database\Seeders;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Admin ──
        User::factory()->admin()->create([
            'name' => 'Admin',
            'email' => 'admin@p2p.com',
            'email_verified_at' => now(),
        ]);

        // ── Investors ──
        $investor1 = User::factory()->kycApproved()->create([
            'name' => 'Иван Петров',
            'email' => 'ivan@test.com',
            'email_verified_at' => now(),
        ]);
        $wallet1 = $investor1->wallet()->create();
        $wallet1->forceFill(['available' => 5000.00, 'invested' => 2000.00, 'earned' => 150.00])->save();

        $investor2 = User::factory()->kycApproved()->create([
            'name' => 'Мария Димитрова',
            'email' => 'maria@test.com',
            'email_verified_at' => now(),
        ]);
        $wallet2 = $investor2->wallet()->create();
        $wallet2->forceFill(['available' => 12000.00, 'invested' => 8000.00, 'earned' => 620.00])->save();

        // ── Originators ──
        $finkredit = Originator::create([
            'name' => 'ФинКредит ООД',
            'description' => 'Водеща финансова институция с над 20 години опит в кредитирането. Над 100 физически офиса. Листвана на фондовата борса.',
            'website' => 'https://finkredit.bg',
            'buyback' => true,
        ]);

        $proimot = Originator::create([
            'name' => 'ПроИмот Кредит АД',
            'description' => 'Иновативна финтех компания специализирана в мостови кредити, обезпечени с недвижими имоти. Използва AI за оценка на риска.',
            'website' => 'https://proimot.bg',
            'buyback' => true,
        ]);

        // ── Borrowers with anonymized profiles ──
        $borrowers = [];
        $profiles = [
            ['risk_class' => 'A', 'region' => 'София', 'loan_purpose' => 'Рефинансиране', 'collateral_type' => 'Недвижим имот', 'age_group' => '36-45'],
            ['risk_class' => 'B', 'region' => 'Пловдив', 'loan_purpose' => 'Потребителски нужди', 'collateral_type' => null, 'age_group' => '26-35'],
            ['risk_class' => 'A', 'region' => 'Варна', 'loan_purpose' => 'Бизнес', 'collateral_type' => 'Поръчителство', 'age_group' => '36-45'],
            ['risk_class' => 'C', 'region' => 'Бургас', 'loan_purpose' => 'Автомобил', 'collateral_type' => 'Автомобил', 'age_group' => '26-35'],
            ['risk_class' => 'B', 'region' => 'Русе', 'loan_purpose' => 'Ремонт', 'collateral_type' => 'Недвижим имот', 'age_group' => '46-55'],
        ];

        for ($i = 0; $i < 5; $i++) {
            $borrower = Borrower::factory()->create();
            $borrower->anonymizedProfile()->create($profiles[$i]);
            $borrowers[] = $borrower;
        }

        // ── Loans (10 with diverse statuses) ──
        $loanData = [
            ['orig' => $finkredit, 'borr' => 0, 'amount' => 25000, 'rate' => 8.0, 'annual' => 10.5, 'months' => 12, 'type' => 'mortgage', 'status' => 'active', 'funded' => 25000],
            ['orig' => $finkredit, 'borr' => 1, 'amount' => 12000, 'rate' => 10.0, 'annual' => 13.0, 'months' => 6, 'type' => 'consumer', 'status' => 'funding', 'funded' => 4800],
            ['orig' => $proimot, 'borr' => 2, 'amount' => 8500, 'rate' => 12.0, 'annual' => 15.0, 'months' => 3, 'type' => 'bridge', 'status' => 'active', 'funded' => 8500],
            ['orig' => $proimot, 'borr' => 3, 'amount' => 6200, 'rate' => 14.0, 'annual' => 17.0, 'months' => 4, 'type' => 'bridge', 'status' => 'published', 'funded' => 0],
            ['orig' => $finkredit, 'borr' => 4, 'amount' => 18000, 'rate' => 11.0, 'annual' => 14.0, 'months' => 9, 'type' => 'business', 'status' => 'funding', 'funded' => 9900],
            ['orig' => $finkredit, 'borr' => 0, 'amount' => 10500, 'rate' => 9.5, 'annual' => 12.0, 'months' => 6, 'type' => 'consumer', 'status' => 'repaid', 'funded' => 10500],
            ['orig' => $proimot, 'borr' => 1, 'amount' => 15000, 'rate' => 13.0, 'annual' => 16.0, 'months' => 6, 'type' => 'bridge', 'status' => 'funded', 'funded' => 15000],
            ['orig' => $finkredit, 'borr' => 2, 'amount' => 30000, 'rate' => 7.5, 'annual' => 9.5, 'months' => 24, 'type' => 'mortgage', 'status' => 'draft', 'funded' => 0],
            ['orig' => $proimot, 'borr' => 3, 'amount' => 5000, 'rate' => 11.5, 'annual' => 14.5, 'months' => 3, 'type' => 'consumer', 'status' => 'late', 'funded' => 5000],
            ['orig' => $finkredit, 'borr' => 4, 'amount' => 20000, 'rate' => 9.0, 'annual' => 11.5, 'months' => 12, 'type' => 'business', 'status' => 'active', 'funded' => 20000],
        ];

        foreach ($loanData as $data) {
            $loan = Loan::create([
                'originator_id' => $data['orig']->id,
                'borrower_id' => $borrowers[$data['borr']]->id,
                'amount' => $data['amount'],
                'funded_amount' => $data['funded'],
                'interest_rate' => $data['rate'],
                'interest_rate_annual' => $data['annual'],
                'term_months' => $data['months'],
                'type' => $data['type'],
                'status' => $data['status'],
                'published_at' => $data['status'] !== 'draft' ? now()->subDays(rand(5, 60)) : null,
            ]);

            // Amortization schedules for active/late loans
            if (in_array($data['status'], ['active', 'late'])) {
                $monthlyPrincipal = bcdiv((string) $data['amount'], (string) $data['months'], 2);
                $monthlyInterest = bcdiv(
                    bcmul((string) $data['amount'], bcdiv((string) $data['rate'], '1200', 6), 2),
                    '1', 2
                );

                for ($m = 1; $m <= $data['months']; $m++) {
                    AmortizationSchedule::create([
                        'loan_id' => $loan->id,
                        'due_date' => Carbon::now()->addMonths($m)->startOfMonth(),
                        'principal' => $monthlyPrincipal,
                        'interest' => $monthlyInterest,
                        'total' => bcadd($monthlyPrincipal, $monthlyInterest, 2),
                        'status' => 'pending',
                    ]);
                }
            }
        }
    }
}
