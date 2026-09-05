<?php

namespace Tests\Unit\Models;

use App\Models\Loan;
use PHPUnit\Framework\TestCase;

/**
 * The edit-form status Select must never offer a money-bearing terminal
 * (repaid / bought_back) or the funded → active activation — those move money
 * and must run through their dedicated payout-performing flows. Manually
 * picking them would strand investor principal (audit HIGH finding).
 */
class LoanStatusSelectionTest extends TestCase
{
    private function loanInStatus(string $status): Loan
    {
        $loan = new Loan;
        $loan->status = $status;

        return $loan;
    }

    public function test_active_loan_cannot_be_manually_marked_repaid(): void
    {
        $selectable = $this->loanInStatus(Loan::STATUS_ACTIVE)->selectableStatusTransitions();

        $this->assertContains(Loan::STATUS_LATE, $selectable);
        $this->assertNotContains(Loan::STATUS_REPAID, $selectable,
            'active → repaid must go through repayment/early-repayment, not the Select');
    }

    public function test_late_loan_cannot_be_manually_marked_repaid_or_bought_back(): void
    {
        $selectable = $this->loanInStatus(Loan::STATUS_LATE)->selectableStatusTransitions();

        // Harmless recovery / marking transitions stay available.
        $this->assertContains(Loan::STATUS_ACTIVE, $selectable);
        $this->assertContains(Loan::STATUS_DEFAULT, $selectable);

        // Money-bearing terminals are removed.
        $this->assertNotContains(Loan::STATUS_REPAID, $selectable);
        $this->assertNotContains(Loan::STATUS_BOUGHT_BACK, $selectable);
    }

    public function test_default_loan_offers_no_manual_terminal(): void
    {
        $selectable = $this->loanInStatus(Loan::STATUS_DEFAULT)->selectableStatusTransitions();

        $this->assertSame([], $selectable,
            'default → repaid / bought_back must both run through their actions');
    }

    public function test_funded_loan_cannot_be_manually_activated(): void
    {
        $selectable = $this->loanInStatus(Loan::STATUS_FUNDED)->selectableStatusTransitions();

        $this->assertNotContains(Loan::STATUS_ACTIVE, $selectable,
            'funded → active must use the activate action so the schedule is generated');
        $this->assertSame([], $selectable);
    }

    public function test_lifecycle_setup_transitions_remain_available(): void
    {
        $this->assertSame(
            [Loan::STATUS_PUBLISHED],
            $this->loanInStatus(Loan::STATUS_DRAFT)->selectableStatusTransitions(),
        );
        $this->assertSame(
            [Loan::STATUS_DRAFT, Loan::STATUS_FUNDING],
            $this->loanInStatus(Loan::STATUS_PUBLISHED)->selectableStatusTransitions(),
        );
        // funding → funded by hand is gone (audit 2026-09-01, PAY-36): since the
        // last euro activates the loan automatically, `funded` is a state no
        // loan rests in — parking one there by hand left it unable to take money
        // or to activate, while the payout engine kept paying it.
        $this->assertSame(
            [Loan::STATUS_DRAFT],
            $this->loanInStatus(Loan::STATUS_FUNDING)->selectableStatusTransitions(),
        );
    }

    public function test_funding_loan_cannot_be_manually_marked_funded(): void
    {
        $this->assertNotContains(
            Loan::STATUS_FUNDED,
            $this->loanInStatus(Loan::STATUS_FUNDING)->selectableStatusTransitions(),
            'funded is reached only by the last investment; a manual funded loan is a dead end',
        );
    }

    public function test_funding_loan_cannot_be_manually_marked_repaid(): void
    {
        // PAY-30: funding → repaid exists for the system (auto-close, full early
        // closure) and stays behind MANUAL_STATUS_BLOCKLIST for the admin Select.
        $this->assertContains(Loan::STATUS_REPAID, Loan::ALLOWED_TRANSITIONS[Loan::STATUS_FUNDING]);
        $this->assertNotContains(
            Loan::STATUS_REPAID,
            $this->loanInStatus(Loan::STATUS_FUNDING)->selectableStatusTransitions(),
        );
    }
}
