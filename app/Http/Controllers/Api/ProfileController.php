<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateCompanyProfileRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\ConsentRecord;
use App\Models\SavedIban;
use App\Models\User;
use App\Notifications\KycSubmittedAdminNotification;
use App\Rules\ValidIban;
use App\Services\AccountDeletionService;
use App\Services\KycImageNormalizer;
use Filament\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    public function submitKyc(Request $request, KycImageNormalizer $images): JsonResponse
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
            //
            // HEIC/HEIF accepted: iPhones shoot HEIC by default and iOS's
            // pick-time transcode produces black/broken files on some devices,
            // so the frontend requests the ORIGINAL and we convert to JPEG
            // here (KycImageNormalizer) before storing.
            'document_front' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif,pdf', 'max:10240'],
            'document_back' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif,pdf', 'max:10240'],
            // Selfie photo — a plain upload (product decision 2026-07-08: the
            // live-camera capture was dropped to keep onboarding easy). Always
            // a photo, never a PDF. Lets the admin face-match the person
            // against the ID document.
            'selfie' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:10240'],
            // Explicit consent for biometric processing of the selfie
            // (GDPR Art. 9(2)(a)) — a separate, un-prechecked opt-in.
            'biometric_consent' => ['accepted'],
        ], [
            'document_front.required' => 'Моля, качете снимка на лицевата страна (отпред) на личната карта.',
            'document_back.required' => 'Моля, качете снимка на гърба (отзад) на личната карта.',
            'selfie.required' => 'Моля, качете ваша снимка (селфи) за верификация.',
            'biometric_consent.accepted' => 'Необходимо е изрично съгласие за обработка на селфи (биометрични данни) за верификация.',
            // The app runs with APP_LOCALE=en, so without these the framework
            // falls back to English messages on a Bulgarian UI.
            'document_front.mimes' => 'Лицевата страна трябва да е снимка (JPG, PNG, WEBP, HEIC) или PDF файл.',
            'document_back.mimes' => 'Гърбът трябва да е снимка (JPG, PNG, WEBP, HEIC) или PDF файл.',
            'selfie.mimes' => 'Селфито трябва да е снимка (JPG, PNG, WEBP или HEIC).',
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

        // HEIC arrives only when the server can actually convert it — without
        // Imagick+libheif we must refuse now, not store files the admin can't
        // open. (Ops: `apt install php-imagick` provides the delegate.)
        $files = [
            'document_front' => $request->file('document_front'),
            'document_back' => $request->file('document_back'),
            'selfie' => $request->file('selfie'),
        ];
        $needsConversion = array_filter($files, fn ($f) => $images->isHeic($f));

        if ($needsConversion !== [] && ! $images->heicSupported()) {
            throw ValidationException::withMessages(array_map(
                fn () => ['Снимки във формат HEIC не могат да бъдат обработени в момента. Изберете снимката като JPEG/PNG или опитайте по-късно.'],
                $needsConversion
            ));
        }

        // Convert BEFORE taking the lock and storing anything: a corrupt HEIC
        // must produce a clear 422 (not a 500), and a mid-sequence failure
        // must not leave already-stored files orphaned on disk.
        $prepared = [];
        foreach ($files as $field => $file) {
            if (! $images->isHeic($file)) {
                $prepared[$field] = ['file' => $file];

                continue;
            }
            try {
                $prepared[$field] = ['jpeg' => $images->toJpeg($file)];
            } catch (\Throwable $e) {
                report($e);
                throw ValidationException::withMessages([
                    $field => ['Снимката не може да бъде обработена — файлът изглежда повреден. Опитайте с друга снимка.'],
                ]);
            }
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
            // Fresh read UNDER the lock (2026-08-07 review): the model was
            // hydrated at request start and the (multi-second) HEIC
            // conversion runs before the lock — an admin decision or an
            // earlier serialized submission may have committed since. The
            // stale snapshot would corrupt BOTH the previous-files capture
            // (orphaned documents) and the email transition guard below
            // (duplicate or suppressed admin email).
            $user->refresh();

            // Re-check after the refresh — an approval that landed during
            // the conversion window must not be silently knocked back to
            // 'submitted'. (No files are stored yet, so returning here
            // orphans nothing.)
            if ($user->kyc_status === 'approved') {
                return response()->json(['message' => 'KYC already approved.'], 422);
            }

            // A resubmission (e.g. after rejection) overwrites the path columns —
            // capture the previous files so we can delete them and not orphan them
            // on disk once the new ones are persisted.
            $previousFiles = array_filter([
                $user->kyc_document_front_path,
                $user->kyc_document_back_path,
                $user->kyc_selfie_path,
            ]);

            $storeKycFile = function (array $item): string {
                if (isset($item['file'])) {
                    return $item['file']->store('kyc-documents', 'local');
                }
                $path = 'kyc-documents/'.Str::random(40).'.jpg';
                Storage::disk('local')->put($path, $item['jpeg']);

                return $path;
            };

            $frontPath = $storeKycFile($prepared['document_front']);
            $backPath = $storeKycFile($prepared['document_back']);
            $selfiePath = $storeKycFile($prepared['selfie']);

            // Captured BEFORE the forceFill below — decides whether this
            // submission enters the review queue anew (email trigger) or
            // just refreshes documents already awaiting review.
            $previousKycStatus = $user->kyc_status;

            // Status flip + consent evidence commit or roll back TOGETHER:
            // if the consent INSERT fails, kyc_status must NOT stay
            // 'submitted' — a durable flip without notifications would
            // suppress the admin email forever for this cycle (the retry
            // would read previous status 'submitted' and skip it).
            DB::transaction(function () use ($user, $request, $frontPath, $backPath, $selfiePath) {
                $user->forceFill([
                    'kyc_status' => 'submitted',
                    'kyc_selfie_path' => $selfiePath,
                    'kyc_document_front_path' => $frontPath,
                    'kyc_document_back_path' => $backPath,
                ])->save();

                // Evidence of explicit Art. 9(2)(a) consent for the biometric
                // selfie, captured at the moment of processing.
                $user->consentRecords()->create([
                    'type' => ConsentRecord::TYPE_BIOMETRIC,
                    'version' => ConsentRecord::CURRENT_BIOMETRIC_VERSION,
                    'ip_address' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                    'accepted_at' => now(),
                ]);
            });

            // Old files removed only AFTER the commit — a rollback must not
            // find the still-referenced previous documents deleted.
            if ($previousFiles !== []) {
                Storage::disk('local')->delete($previousFiles);
            }
        } finally {
            $lock->release();
        }

        // In-panel inbox alert for the reviewers (bell icon in Filament).
        // Best-effort, isolated PER DISPATCH: one failed send must neither
        // fail the submission nor suppress the remaining bells/emails.
        $safeNotify = function (User $admin, $notification, bool $now = false): void {
            try {
                $now ? $admin->notifyNow($notification) : $admin->notify($notification);
            } catch (\Throwable $e) {
                report($e);
            }
        };

        $viewUrl = url('/admin/users/'.$user->id);
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
            // notifyNow() bypasses ShouldQueue and inserts the row inline,
            // restoring the documented deliberate behavior. The name is
            // e()-escaped: Filament renders bell bodies as sanitized HTML
            // (not escaped text), so a raw name could smuggle a live link
            // into the admin panel.
            $safeNotify($admin, FilamentNotification::make()
                ->title('Нова KYC заявка')
                ->body(e($user->name).' изпрати документи за верификация.')
                ->icon('heroicon-o-identification')
                ->info()
                ->actions([
                    FilamentAction::make('view')->label('Преглед')->url($viewUrl)->markAsRead(),
                ])
                ->toDatabase(), now: true);
        }

        // EMAIL to the reviewers — event-driven (client request 2026-08-07:
        // "когато има какво, без час"), but only when the submission enters
        // the review queue anew: in practice pending or rejected → submitted
        // (approved users are 422-blocked earlier in this method; the guard
        // still handles 'approved' correctly should that policy ever be
        // relaxed). A re-upload while already awaiting review refreshes the
        // documents and the bell above without re-paging the admin's inbox.
        if (! in_array($previousKycStatus, ['submitted', 'in_review'], true)) {
            $submittedAt = now();
            foreach ($admins as $admin) {
                $safeNotify($admin, new KycSubmittedAdminNotification(
                    applicantId: $user->id,
                    applicantName: $user->name,
                    applicantEmail: $user->email,
                    accountType: $user->account_type,
                    submittedAt: $submittedAt,
                ));
            }
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
