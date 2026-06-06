<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\SavedIban;
use App\Rules\ValidIban;
use App\Services\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(new UserResource($request->user()->load('wallet')));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $request->user()->update($request->only('name', 'phone'));

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => new UserResource($request->user()->fresh()->load('wallet')),
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        // Revoke every Sanctum token so a stolen/leaked token cannot survive
        // a password change — the whole point of changing a password after a
        // suspected compromise is to lock the attacker out. The current
        // session is preserved by the SPA cookie guard (not a token).
        $user->tokens()->delete();

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function submitKyc(Request $request): JsonResponse
    {
        $request->validate([
            // Explicit MIME allow-list — Laravel's `image` rule includes SVG which
            // can carry JavaScript and execute when admin views the document
            // (admin session takeover). PDF added for ID-document scans.
            'document' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        $user = $request->user();

        if ($user->kyc_status === 'approved') {
            return response()->json(['message' => 'KYC already approved.'], 422);
        }

        $path = $request->file('document')->store('kyc-documents', 'local');

        $user->forceFill([
            'kyc_status' => 'submitted',
            'kyc_document_path' => $path,
        ])->save();

        return response()->json(['message' => 'KYC document submitted successfully.']);
    }

    // ── Saved IBANs ──

    public function ibans(Request $request): JsonResponse
    {
        $ibans = $request->user()->savedIbans()->latest()->get();

        return response()->json([
            'data' => $ibans->map(fn (SavedIban $iban) => [
                'id' => $iban->id,
                'iban' => $iban->maskedIban(),
                'label' => $iban->label,
                'created_at' => $iban->created_at,
            ]),
        ]);
    }

    public function storeIban(Request $request): JsonResponse
    {
        $request->validate([
            // Format/checksum/SEPA-country validation in one rule.
            'iban' => ['required', 'string', 'min:15', 'max:34', new ValidIban],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $iban = $request->user()->savedIbans()->create([
            'iban' => $request->iban,
            'label' => $request->label,
        ]);

        return response()->json([
            'message' => 'IBAN saved successfully.',
            'iban' => [
                'id' => $iban->id,
                'iban' => $iban->maskedIban(),
                'label' => $iban->label,
            ],
        ], 201);
    }

    public function destroyIban(Request $request, SavedIban $iban): JsonResponse
    {
        $this->authorize('delete', $iban);

        $iban->delete();

        return response()->json(['message' => 'IBAN removed.']);
    }

    public function deleteAccount(Request $request, AccountDeletionService $service): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $service->deleteAccount($request->user(), $request->password);

        auth()->guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Account deleted successfully.']);
    }
}
