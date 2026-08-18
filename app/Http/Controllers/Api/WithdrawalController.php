<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WithdrawalRequest as WithdrawalFormRequest;
use App\Http\Resources\WalletResource;
use App\Http\Resources\WithdrawalRequestResource;
use App\Models\SavedIban;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Notifications\WithdrawalRequestedAdminNotification;
use App\Services\WithdrawalService;
use App\Support\Money;
use Filament\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function __construct(private WithdrawalService $withdrawalService) {}

    public function store(WithdrawalFormRequest $request): JsonResponse
    {
        $this->authorize('create', WithdrawalRequest::class);
        // Resolve IBAN: either from saved IBAN (server-side, never exposed) or raw input
        if ($request->filled('saved_iban_id')) {
            $savedIban = SavedIban::where('id', $request->saved_iban_id)
                ->where('user_id', $request->user()->id)
                ->firstOrFail();
            $iban = $savedIban->iban;
        } else {
            $iban = $request->iban;
        }

        try {
            // bcmath-safe ingress — never float-cast user input.
            $amount = Money::normalizePositive($request->amount);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $withdrawal = $this->withdrawalService->createRequest(
            $request->user()->id,
            $amount,
            $iban
        );

        // Alert the reviewers — the money is already reserved and committed
        // by the service, so notifications come strictly after (money first).
        // Best-effort, isolated PER DISPATCH: one failed send must neither
        // fail the user's withdrawal nor suppress the remaining bells/emails.
        $safeNotify = function (User $admin, $notification, bool $now = false): void {
            try {
                $now ? $admin->notifyNow($notification) : $admin->notify($notification);
            } catch (\Throwable $e) {
                report($e);
            }
        };

        $reviewUrl = url('/admin/withdrawal-requests');
        $requestedAt = now();
        try {
            $admins = User::where('role', 'admin')->get();
        } catch (\Throwable $e) {
            report($e);
            $admins = collect();
        }
        foreach ($admins as $admin) {
            // notifyNow: Filament v5's DatabaseNotification implements
            // ShouldQueue, so a plain notify() would ride the queue and
            // panel visibility would depend on a worker being alive —
            // notifyNow() inserts the row inline (same deliberate choice
            // as the KYC submission bell). The name is e()-escaped:
            // Filament renders bell bodies as sanitized HTML (not escaped
            // text), so a raw name could smuggle a live link into the panel.
            $safeNotify($admin, FilamentNotification::make()
                ->title('Ново заявено теглене')
                ->body(e($request->user()->name).' заяви теглене на '.$amount.' €.')
                ->icon('heroicon-o-banknotes')
                ->warning()
                ->actions([
                    FilamentAction::make('view')->label('Преглед')->url($reviewUrl)->markAsRead(),
                ])
                ->toDatabase(), now: true);

            $safeNotify($admin, new WithdrawalRequestedAdminNotification(
                withdrawalId: $withdrawal->id,
                investorName: $request->user()->name,
                amount: $amount,
                requestedAt: $requestedAt,
            ));
        }

        return response()->json([
            'message' => 'Withdrawal request created successfully.',
            'withdrawal' => new WithdrawalRequestResource($withdrawal),
        ], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WithdrawalRequest::class);

        $withdrawals = WithdrawalRequest::where('user_id', $request->user()->id)
            // Newest request first; `id` keeps paging deterministic when two
            // requests share a created_at second.
            ->latest()
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json([
            'data' => WithdrawalRequestResource::collection($withdrawals),
            'meta' => [
                'current_page' => $withdrawals->currentPage(),
                'last_page' => $withdrawals->lastPage(),
                'per_page' => $withdrawals->perPage(),
                'total' => $withdrawals->total(),
            ],
        ]);
    }

    public function wallet(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Wallet::class);

        return response()->json(new WalletResource($request->user()->wallet));
    }
}
