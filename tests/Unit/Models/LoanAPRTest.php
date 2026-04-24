<?php

namespace Tests\Unit\Models;

use App\Models\Loan;
use App\Services\APRCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F5 — Loan::apr() accessor tests.
 *
 * The method delegates to APRCalculatorService with per-instance
 * memoization. These tests pin both the delegation contract and
 * the memoization behaviour so a future refactor that loses either
 * property fails CI loudly.
 */
class LoanAPRTest extends TestCase
{
    use RefreshDatabase;

    public function test_apr_method_delegates_to_calculator_service(): void
    {
        $loan = Loan::factory()->create(['interest_rate_annual' => '15.75']);

        $this->assertSame('15.75', $loan->apr());
    }

    public function test_apr_returns_null_for_zero_rate(): void
    {
        $loan = Loan::factory()->make(['interest_rate_annual' => 0]);

        $this->assertNull($loan->apr());
    }

    public function test_apr_memoized_across_multiple_calls(): void
    {
        // Mock the service and verify calculate() is called ONCE even
        // when apr() is called three times. Protects the memo
        // contract — if someone accidentally drops the $aprMemoResolved
        // flag the test fires immediately.
        $loan = Loan::factory()->create(['interest_rate_annual' => '10.00']);

        $mock = $this->createMock(APRCalculatorService::class);
        $mock->expects($this->once())
            ->method('calculate')
            ->with($loan)
            ->willReturn('10.00');
        $this->app->instance(APRCalculatorService::class, $mock);

        $loan->apr();
        $loan->apr();
        $loan->apr();
    }

    public function test_apr_memo_caches_null_result(): void
    {
        // Null is a valid cached outcome — the memo must NOT retry the
        // calculation when the cached value is null. Regression guard
        // for the "forgot $aprMemoResolved flag" class of bug.
        $loan = Loan::factory()->make(['interest_rate_annual' => 0]);

        $mock = $this->createMock(APRCalculatorService::class);
        $mock->expects($this->once())
            ->method('calculate')
            ->willReturn(null);
        $this->app->instance(APRCalculatorService::class, $mock);

        $this->assertNull($loan->apr());
        $this->assertNull($loan->apr());
    }
}
