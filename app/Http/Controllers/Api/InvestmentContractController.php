<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\LoanOffer;
use App\Services\InvestmentContractService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Investor-facing contract PDFs.
 *
 *  - download(): the CONCLUDED contract of an own investment, rendered
 *    from its frozen snapshot (InvestmentPolicy::view — owner or admin).
 *  - preview(): a watermarked «ПРОЕКТ» draft from live data, so the
 *    investor can read the exact document BEFORE the invest click
 *    concludes it (click-wrap consent needs an accessible text upfront).
 *
 * Responses are inline PDFs — opening in a browser tab works with the
 * Sanctum SPA cookie session, no token plumbing needed.
 */
class InvestmentContractController extends Controller
{
    public function download(Request $request, Investment $investment, InvestmentContractService $service): Response
    {
        // 404 (not 403) for foreign investments — a 403/404 split would let
        // any investor enumerate which sequential investment ids exist.
        if (! $request->user()->can('view', $investment)) {
            abort(404);
        }

        $contract = $investment->contract;
        abort_if($contract === null, 404, 'No contract exists for this investment.');

        return $this->pdfResponse(
            $service->renderPdf($contract),
            "dogovor-zaem-inv-{$investment->id}.pdf",
        );
    }

    public function preview(Request $request, Loan $loan, InvestmentContractService $service): Response
    {
        $this->authorize('view', $loan);

        // Drafts exist only for loans that can actually be invested in —
        // mirrors InvestmentService::validateInvestment. Without this, a
        // dated company-identified draft could be rendered for repaid/
        // bought-back/late loans the platform is not offering.
        if (! in_array($loan->status, Loan::FUNDABLE_STATUSES)) {
            throw ValidationException::withMessages([
                'loan' => ['Този кредит не е отворен за инвестиции.'],
            ]);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:50', 'max:999999.99', 'decimal:0,2'],
            'loan_offer_id' => ['required', 'integer'],
        ]);

        // Same offer scoping as the invest path: must belong to this loan
        // and still be enabled.
        $offer = LoanOffer::where('id', (int) $validated['loan_offer_id'])
            ->where('loan_id', $loan->id)
            ->where('is_enabled', true)
            ->first();

        if ($offer === null) {
            throw ValidationException::withMessages([
                'loan_offer_id' => ['Избраната оферта не е налична за този кредит.'],
            ]);
        }

        $amount = Money::normalizePositive($validated['amount']);

        return $this->pdfResponse(
            $service->renderPreviewPdf($request->user(), $loan, $offer, $amount),
            "dogovor-zaem-proekt-{$loan->id}.pdf",
        );
    }

    private function pdfResponse(string $pdf, string $filename): Response
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            // PII inside — never cache on shared infrastructure.
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
