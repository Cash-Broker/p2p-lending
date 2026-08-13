<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PromotionService;
use Illuminate\Http\JsonResponse;

class PromotionController extends Controller
{
    /**
     * Running flash promos for the dashboard panel («динамичен панел...
     * офертата валидна за 60 мин»). Countdown runs client-side off ends_at;
     * the SPA re-polls to catch new promos and drop finished ones.
     */
    public function active(PromotionService $promotions): JsonResponse
    {
        return response()->json([
            'promotions' => $promotions->activeForDisplay()->values(),
        ]);
    }
}
