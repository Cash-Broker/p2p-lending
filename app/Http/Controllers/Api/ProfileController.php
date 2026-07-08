<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateCompanyProfileRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\ConsentRecord;
use App\Models\SavedIban;
use App\Rules\ValidIban;
use App\Services\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(new UserResource($request->user()->load(['wallet', 'legalEntityProfile'])));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $request->user()->update($request->only('name', 'phone'));

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => new UserResource($request->user()->fresh()->load(['wallet', 'legalEntityProfile'])),
        ]);
    }

    /**
     * Update the editable part of a legal-entity's company profile.
     *
     * Identity fields (legal_name, eik) are not accepted here — see
     * UpdateCompanyProfileRequest. The authorize() there already restricts this
     * to legal-entity accounts (403 otherwise), so by the time we're here the
     * profile row exists for any account created through the registration flow.
     */
    public function updateCompany(UpdateCompanyProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->legalEntityProfile;

        if ($profile === null) {
            return response()->json(['message' => 'Company profile not found.'], 404);
        }

        $profile->update($request->validated());

        return response()->json([
            'message' => 'Company profile updated successfully.',
            'user' => new UserResource($user->fresh()->load(['wallet', 'legalEntityProfile'])),
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $request->user()->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function submitKyc(Request $request): JsonResponse
    {
        $request->validate([
            // Both sides of the ID are mandatory — users routinely uploaded only
            // the front, leaving us unable to verify identity. Requiring two
            // distinct files at the validation layer makes the requirement
            // impossible to miss.
            //
            // Explicit MIME allow-list — Laravel's `image` rule includes SVG which
            // can carry JavaScript and execute when admin views the document
            // (admin session takeover). PDF added for ID-document scans.
            'document_front' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'document_back' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            // Liveness selfie — captured live from the camera on the client, so
            // it's always a photo (no PDF). Lets the admin face-match the person
            // against the ID document.
            'selfie' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            // Explicit consent for biometric processing of the selfie
            // (GDPR Art. 9(2)(a)) — a separate, un-prechecked opt-in.
            'biometric_consent' => ['accepted'],
        ], [
            'document_front.required' => 'Моля, качете снимка на лицевата страна (отпред) на личната карта.',
            'document_back.required' => 'Моля, качете снимка на гърба (отзад) на личната карта.',
            'selfie.required' => 'Моля, направете селфи с камерата за верификация.',
            'biometric_consent.accepted' => 'Необходимо е изрично съгласие за обработка на селфи (биометрични данни) за верификация.',
            // The app runs with APP_LOCALE=en, so without these the framework
            // falls back to English messages on a Bulgarian UI. HEIC is the
            // dominant real-world rejection (iPhone/Samsung default format).
            'document_front.mimes' => 'Лицевата страна трябва да е JPG, PNG, WEBP или PDF файл. Снимки във формат HEIC (iPhone) не се поддържат — направете снимката отново или я изберете като JPEG.',
            'document_back.mimes' => 'Гърбът трябва да е JPG, PNG, WEBP или PDF файл. Снимки във формат HEIC (iPhone) не се поддържат — направете снимката отново или я изберете като JPEG.',
            'selfie.mimes' => 'Селфито трябва да е JPG, PNG или WEBP изображение.',
            'document_front.max' => 'Файлът за лицевата страна не може да е по-голям от 10 MB.',
            'document_back.max' => 'Файлът за гърба не може да е по-голям от 10 MB.',
            'selfie.max' => 'Селфито не може да е по-голямо от 10 MB.',
            // `uploaded` fires when PHP itself refused the upload (ini limits) —
            // the file never reached the validator.
            'document_front.uploaded' => 'Качването на лицевата страна не бе успешно — файлът вероятно е твърде голям.',
            'document_back.uploaded' => 'Качването на гърба не бе успешно — файлът вероятно е твърде голям.',
            'selfie.uploaded' => 'Качването на селфито не бе успешно — файлът вероятно е твърде голям.',
        ]);

        $user = $request->user();

        if ($user->kyc_status === 'approved') {
            return response()->json(['message' => 'KYC already approved.'], 422);
        }

        // Per-user lock: the read-previous → store → save → delete sequence
        // below is not atomic. Concurrent submissions would each capture the
        // SAME previous paths, so all but one set of freshly stored ID
        // documents would be orphaned on disk — unreferenced by any user row,
        // surviving both resubmission cleanup and account deletion (GDPR).
        $lock = Cache::lock('kyc-submit:'.$user->id, 15);

        if (! $lock->get()) {
            return response()->json(['message' => 'Вече се обработва изпращане за верификация. Изчакайте момент и опитайте отново.'], 429);
        }

        try {
            // A resubmission (e.g. after rejection) overwrites the path columns —
            // capture the previous files so we can delete them and not orphan them
            // on disk once the new ones are persisted.
            $previousFiles = array_filter([
                $user->kyc_document_front_path,
                $user->kyc_document_back_path,
                $user->kyc_selfie_path,
            ]);

            $frontPath = $request->file('document_front')->store('kyc-documents', 'local');
            $backPath = $request->file('document_back')->store('kyc-documents', 'local');
            $selfiePath = $request->file('selfie')->store('kyc-documents', 'local');

            $user->forceFill([
                'kyc_status' => 'submitted',
                'kyc_selfie_path' => $selfiePath,
                'kyc_document_front_path' => $frontPath,
                'kyc_document_back_path' => $backPath,
            ])->save();

            if ($previousFiles !== []) {
                Storage::disk('local')->delete($previousFiles);
            }

            // Evidence of explicit Art. 9(2)(a) consent for the biometric selfie,
            // captured at the moment of processing.
            $user->consentRecords()->create([
                'type' => ConsentRecord::TYPE_BIOMETRIC,
                'version' => ConsentRecord::CURRENT_BIOMETRIC_VERSION,
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'accepted_at' => now(),
            ]);
        } finally {
            $lock->release();
        }

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
