<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FeeService;
use Illuminate\Http\JsonResponse;

/**
 * Public read-only fee-config endpoint — consumed by the investor SPA
 * (WithdrawalPage.vue breakdown) and by any future fee-aware surface.
 *
 * Shape intentionally nested by category so additional categories
 * (origination / service / late / early_repayment / inactivity) can be
 * added alongside `withdrawal` without a breaking change for existing
 * SPA consumers.
 *
 * No auth — the enabled flag and flat amount are public-ish information
 * already advertised on the landing FAQ and chatbot (amended in commit
 * f301c19 to "безплатно" while the flag is off; once the flag flips to
 * true, the copy should describe the concrete fee too). Rate-limited
 * 60/min to deflect abuse.
 *
 * No API Resource class — the payload is derived from platform_settings
 * rows, not models. A Resource wrapper around a non-model payload would
 * add ceremony without value. Mirrors SchedulerHealthController's direct
 * JsonResponse shape.
 */
class FeeController extends Controller
{
    public function __construct(private FeeService $feeService) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'withdrawal' => [
                'enabled' => $this->feeService->isWithdrawalFeeEnabled(),
                'amount'  => $this->feeService->getAmount(FeeService::CATEGORY_WITHDRAWAL),
            ],
        ]);
    }
}
