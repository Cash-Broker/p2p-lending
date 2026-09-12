<?php

namespace Tests\Feature\Notifications;

use App\Enums\PayoutType;
use App\Models\Investment;
use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\ScheduledPayoutReceivedNotification;
use App\Services\InvestmentService;
use App\Services\ScheduledPayoutNotifier;
use App\Services\ScheduledPayoutService;
use App\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * «Получено плащане» mail + bell after a scheduled payout (2026-09-12).
 *
 * The money path is untouched — every assertion here is about WHO is told
 * WHAT, and about the two things that must never happen: a mail problem
 * failing a paid loan, and a re-send mailing the same installment twice.
 */
class ScheduledPayoutReceivedNotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One loan, three investors of 1 000 € each — one per plan — so a single
     * run exercises the release path (amortizing, interest-only) and the
     * accrual path (capitalized) side by side.
     *
     * @return array{0: Loan, 1: array<string, User>}
     */
    private function loanWithThreePlans(string $payoutMode = Loan::PAYOUT_MODE_AUTOMATIC): array
    {
        $loan = Loan::factory()->published()->create([
            'amount' => 3000, 'investable_amount' => 3000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
            'payout_mode' => $payoutMode,
        ]);

        $users = [];
        foreach (PayoutType::cases() as $type) {
            $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
            $user->wallet()->create();
            app(WalletService::class)->credit($user->id, '1000.00', Transaction::TYPE_DEPOSIT, 'seed');

            $offerId = $loan->offers()->where('payout_type', $type)->value('id');
            app(InvestmentService::class)->invest($user, $loan->fresh(), '1000.00', (string) Str::uuid(), $offerId);

            $users[$type->value] = $user;
        }

        $loan->refresh();
        if ($loan->status !== Loan::STATUS_ACTIVE) {
            $loan->transitionTo(Loan::STATUS_ACTIVE);
        }

        return [$loan->refresh(), $users];
    }

    private function firstRowOf(User $user): InvestmentSchedule
    {
        return InvestmentSchedule::query()
            ->whereIn('investment_id', Investment::where('user_id', $user->id)->select('id'))
            ->orderBy('due_date')
            ->orderBy('id')
            ->firstOrFail();
    }

    public function test_cron_run_on_the_first_due_date_mails_the_released_plans_and_not_the_accruing_one(): void
    {
        Notification::fake();
        [$loan, $users] = $this->loanWithThreePlans();

        $firstDue = Carbon::parse($loan->investmentSchedules()->min('due_date'));
        $result = app(ScheduledPayoutService::class)->runAllAutomatic($firstDue);

        $this->assertSame(1, $result['loans_processed']);
        $this->assertSame(0, $result['loans_failed']);

        foreach ([PayoutType::Amortizing, PayoutType::InterestOnly] as $type) {
            $user = $users[$type->value];
            $row = $this->firstRowOf($user)->fresh();
            $this->assertSame('paid', $row->status);

            Notification::assertSentToTimes($user, ScheduledPayoutReceivedNotification::class, 1);
            Notification::assertSentTo($user, ScheduledPayoutReceivedNotification::class, function (ScheduledPayoutReceivedNotification $n) use ($loan, $row, $type) {
                return $n->loanId === $loan->id
                    && $n->scheduleIds === [$row->id]
                    && $n->principal === (string) $row->principal
                    && $n->interest === (string) $row->interest
                    && $n->total === (string) $row->total
                    && $n->payoutType === $type
                    && $n->isFinal === false
                    && count($n->installments) === 1
                    && $n->installments[0]['due_date'] === $row->due_date->toDateString()
                    && $n->paidOn->toDateString() === now()->toDateString();
            });
        }

        // Interest-only: the monthly row is pure interest, and the mail says so.
        Notification::assertSentTo($users['interest_only'], ScheduledPayoutReceivedNotification::class,
            fn (ScheduledPayoutReceivedNotification $n) => $n->principal === '0.00' && bccomp($n->interest, '0', 2) > 0);

        // Capitalized accrues into `accrued` this month — nothing was PAID, no mail.
        Notification::assertNotSentTo($users['capitalized'], ScheduledPayoutReceivedNotification::class);
    }

    public function test_capitalized_investor_is_told_once_at_maturity_with_principal_and_the_final_flag(): void
    {
        Notification::fake();
        [$loan, $users] = $this->loanWithThreePlans();

        // The whole term has elapsed in one run (the same catch-up the cron
        // would do after an outage): every plan ends here.
        $result = app(ScheduledPayoutService::class)->runForLoan($loan, now()->addDays(400));

        $this->assertSame(['sent' => 3, 'skipped' => 0], $result['notified']);

        $capRow = $this->firstRowOf($users['capitalized'])->fresh();
        Notification::assertSentToTimes($users['capitalized'], ScheduledPayoutReceivedNotification::class, 1);
        Notification::assertSentTo($users['capitalized'], ScheduledPayoutReceivedNotification::class,
            fn (ScheduledPayoutReceivedNotification $n) => $n->principal === '1000.00'
                && $n->interest === (string) $capRow->interest
                && $n->total === bcadd('1000.00', (string) $capRow->interest, 2)
                && $n->payoutType === PayoutType::Capitalized
                && $n->isFinal === true
                && $n->scheduleIds === [$capRow->id]);

        // Amortizing: 12 installments caught up in ONE run ⇒ ONE mail listing all 12.
        Notification::assertSentToTimes($users['amortizing'], ScheduledPayoutReceivedNotification::class, 1);
        Notification::assertSentTo($users['amortizing'], ScheduledPayoutReceivedNotification::class,
            fn (ScheduledPayoutReceivedNotification $n) => count($n->installments) === 12
                && count($n->scheduleIds) === 12
                && $n->principal === '1000.00'
                && $n->isFinal === true);
    }

    public function test_run_before_the_first_due_date_sends_nothing(): void
    {
        Notification::fake();
        [$loan, $users] = $this->loanWithThreePlans();

        $result = app(ScheduledPayoutService::class)->runForLoan($loan, now());

        $this->assertSame(['sent' => 0, 'skipped' => 0], $result['notified']);
        foreach ($users as $user) {
            Notification::assertNotSentTo($user, ScheduledPayoutReceivedNotification::class);
        }
    }

    public function test_manual_payout_button_path_notifies_too(): void
    {
        Notification::fake();
        [$loan, $users] = $this->loanWithThreePlans(Loan::PAYOUT_MODE_MANUAL);

        // «Пусни плащане сега» calls runForLoan directly — same entry point, same mail.
        $firstDue = Carbon::parse($loan->investmentSchedules()->min('due_date'));
        $result = app(ScheduledPayoutService::class)->runForLoan($loan, $firstDue);

        $this->assertSame(['sent' => 2, 'skipped' => 0], $result['notified']);
        Notification::assertSentTo($users['amortizing'], ScheduledPayoutReceivedNotification::class);
    }

    public function test_a_notification_failure_never_turns_a_paid_loan_into_a_failed_one(): void
    {
        Notification::fake();
        [$loan, $users] = $this->loanWithThreePlans();

        $this->partialMock(ScheduledPayoutNotifier::class, function ($mock) {
            $mock->shouldReceive('notifyRows')->andThrow(new \RuntimeException('smtp down'));
        });

        $firstDue = Carbon::parse($loan->investmentSchedules()->min('due_date'));
        $result = app(ScheduledPayoutService::class)->runAllAutomatic($firstDue);

        $this->assertSame(1, $result['loans_processed']);
        $this->assertSame(0, $result['loans_failed'], 'a mail problem must not report investors as unpaid');
        $this->assertSame('paid', $this->firstRowOf($users['amortizing'])->fresh()->status, 'money moved regardless');
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_kill_switch_silences_the_mail_but_not_the_money(): void
    {
        Notification::fake();
        [$loan, $users] = $this->loanWithThreePlans();
        PlatformSetting::set(ScheduledPayoutNotifier::SETTING_ENABLED, false);

        $firstDue = Carbon::parse($loan->investmentSchedules()->min('due_date'));
        $result = app(ScheduledPayoutService::class)->runForLoan($loan, $firstDue);

        $this->assertSame(['sent' => 0, 'skipped' => 0], $result['notified']);
        $this->assertSame('paid', $this->firstRowOf($users['amortizing'])->fresh()->status);
        Notification::assertNotSentTo($users['amortizing'], ScheduledPayoutReceivedNotification::class);
    }

    public function test_resend_command_dedupes_on_the_schedule_rows(): void
    {
        // Real channels on purpose (sync queue, array mailer): the dedupe reads
        // the `notifications` table the database channel writes.
        [$loan, $users] = $this->loanWithThreePlans();

        $firstDue = Carbon::parse($loan->investmentSchedules()->min('due_date'));
        $result = app(ScheduledPayoutService::class)->runForLoan($loan, $firstDue);
        $this->assertSame(['sent' => 2, 'skipped' => 0], $result['notified']);

        $stored = fn () => DB::table('notifications')->where('type', ScheduledPayoutReceivedNotification::class)->count();
        $this->assertSame(2, $stored());

        $amortizingRow = $this->firstRowOf($users['amortizing'])->fresh();
        $this->assertTrue(
            DB::table('notifications')
                ->where('type', ScheduledPayoutReceivedNotification::class)
                ->where('notifiable_id', $users['amortizing']->id)
                ->whereJsonContains('data->schedule_ids', $amortizingRow->id)
                ->exists(),
            'the bell row carries the paid schedule id the dedupe keys on',
        );

        // Same day re-sent by hand: everything is skipped, nothing is stored twice.
        $this->artisan('payouts:notify-paid', ['--date' => now()->toDateString()])
            ->expectsOutputToContain('0 notification(s) sent, 2 skipped')
            ->assertExitCode(0);
        $this->assertSame(2, $stored());

        // The notification itself refuses a duplicate even when called directly
        // (queue retry) — via() goes empty.
        $again = new ScheduledPayoutReceivedNotification(
            loanId: $loan->id, scheduleIds: [$amortizingRow->id], installments: [], principal: '0.00',
            interest: '0.00', total: '0.00', payoutType: null, isFinal: false, paidOn: now(),
        );
        $this->assertSame([], $again->via($users['amortizing']));
        $this->assertSame(['mail', 'database'], $again->via($users['capitalized']));
    }

    public function test_resend_command_backfills_a_day_whose_run_sent_nothing(): void
    {
        Notification::fake();
        [$loan, $users] = $this->loanWithThreePlans();

        // The 2026-09-12 situation: rows were paid before the mail existed.
        PlatformSetting::set(ScheduledPayoutNotifier::SETTING_ENABLED, false);
        $firstDue = Carbon::parse($loan->investmentSchedules()->min('due_date'));
        app(ScheduledPayoutService::class)->runForLoan($loan, $firstDue);
        Notification::assertNothingSentTo($users['amortizing']);
        PlatformSetting::set(ScheduledPayoutNotifier::SETTING_ENABLED, true);

        // Dry run lists, sends nothing.
        $this->artisan('payouts:notify-paid', ['--date' => now()->toDateString(), '--dry-run' => true])
            ->expectsOutputToContain('Dry run: 2 notification(s) would be sent')
            ->assertExitCode(0);
        Notification::assertNothingSentTo($users['amortizing']);

        // Wrong loan filter: nothing to announce.
        $this->artisan('payouts:notify-paid', ['--date' => now()->toDateString(), '--loan' => $loan->id + 1000])
            ->expectsOutputToContain('nothing to announce')
            ->assertExitCode(0);
        Notification::assertNothingSentTo($users['amortizing']);

        // Real run: the two released plans get their mail, the accruing one does not.
        $this->artisan('payouts:notify-paid', ['--date' => now()->toDateString(), '--loan' => $loan->id])
            ->expectsOutputToContain('2 notification(s) sent, 0 skipped')
            ->assertExitCode(0);
        Notification::assertSentToTimes($users['amortizing'], ScheduledPayoutReceivedNotification::class, 1);
        Notification::assertSentToTimes($users['interest_only'], ScheduledPayoutReceivedNotification::class, 1);
        Notification::assertNotSentTo($users['capitalized'], ScheduledPayoutReceivedNotification::class);

        // A day with no payouts, and a future day, are both harmless.
        $this->artisan('payouts:notify-paid', ['--date' => now()->subDays(3)->toDateString()])
            ->expectsOutputToContain('nothing to announce')
            ->assertExitCode(0);
        $this->artisan('payouts:notify-paid', ['--date' => now()->addDay()->toDateString()])
            ->assertExitCode(1);
    }

    public function test_resend_skips_an_account_closed_since_the_payout(): void
    {
        Notification::fake();
        [$loan, $users] = $this->loanWithThreePlans();

        PlatformSetting::set(ScheduledPayoutNotifier::SETTING_ENABLED, false);
        app(ScheduledPayoutService::class)->runForLoan($loan, Carbon::parse($loan->investmentSchedules()->min('due_date')));
        PlatformSetting::set(ScheduledPayoutNotifier::SETTING_ENABLED, true);

        // The amortizing investor has since been anonymised (SEC-22 shape).
        $users['amortizing']->forceFill(['email' => 'u'.$users['amortizing']->id.'@deleted.invalid', 'deletion_finalized_at' => now()])->save();

        $this->artisan('payouts:notify-paid', ['--date' => now()->toDateString()])
            ->expectsOutputToContain('1 notification(s) sent, 0 skipped')
            ->assertExitCode(0);

        Notification::assertNotSentTo($users['amortizing'], ScheduledPayoutReceivedNotification::class);
        Notification::assertSentToTimes($users['interest_only'], ScheduledPayoutReceivedNotification::class, 1);
    }

    public function test_mail_and_bell_payload_carry_the_investors_own_numbers_only(): void
    {
        $user = User::factory()->create(['name' => 'Иван Инвеститор']);

        $notification = new ScheduledPayoutReceivedNotification(
            loanId: 3,
            scheduleIds: [41, 42],
            installments: [
                ['due_date' => '2026-08-12', 'principal' => '80.00', 'interest' => '4.66', 'total' => '84.66'],
                ['due_date' => '2026-09-12', 'principal' => '80.50', 'interest' => '4.16', 'total' => '84.66'],
            ],
            principal: '160.50',
            interest: '8.82',
            total: '169.32',
            payoutType: PayoutType::Amortizing,
            isFinal: true,
            paidOn: Carbon::parse('2026-09-12 04:00:02'),
        );

        $mail = $notification->toMail($user);
        $body = implode("\n", array_map('strval', $mail->introLines)).implode("\n", array_map('strval', $mail->outroLines));

        $this->assertSame('Получено плащане 169.32 € по кредит #3 — Vamaasset', $mail->subject);
        $this->assertStringContainsString('12.09.2026', $body);
        $this->assertStringContainsString('план «Анюитет»', $body);
        $this->assertStringContainsString('вноска с падеж 12.08.2026 — 84.66 €', $body);
        $this->assertStringContainsString('Главница: 160.50 €', $body);
        $this->assertStringContainsString('Лихва: 8.82 €', $body);
        $this->assertStringContainsString('Общо: 169.32 €', $body);
        $this->assertStringContainsString('последната вноска', $body);
        $this->assertStringContainsString('реинвестирате', $body);
        $this->assertStringEndsWith('/portfolio', $mail->actionUrl);

        $data = $notification->toArray($user);
        $this->assertSame('scheduled_payout_received', $data['type']);
        $this->assertSame('169.32', $data['amount']);
        $this->assertSame([41, 42], $data['schedule_ids']);
        $this->assertSame('amortizing', $data['payout_type']);
        $this->assertTrue($data['is_final']);
        $this->assertSame('2026-09-12', $data['paid_on']);
        $this->assertStringContainsString('160.50 € главница', $data['message']);

        // Interest-only shape: no principal line at all, no plan when mixed.
        $interestOnly = new ScheduledPayoutReceivedNotification(
            loanId: 3, scheduleIds: [43], installments: [['due_date' => '2026-09-12', 'principal' => '0.00', 'interest' => '4.66', 'total' => '4.66']],
            principal: '0.00', interest: '4.66', total: '4.66', payoutType: null, isFinal: false, paidOn: Carbon::parse('2026-09-12'),
        );
        $body = implode("\n", array_map('strval', $interestOnly->toMail($user)->introLines));
        $this->assertStringNotContainsString('Главница', $body);
        $this->assertStringNotContainsString('план «', $body);
        $this->assertStringNotContainsString('последната вноска', $body);
        $this->assertStringNotContainsString('вноска с падеж', $body, 'a single installment is not itemised');
    }
}
