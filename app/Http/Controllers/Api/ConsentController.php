<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsentRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConsentController extends Controller
{
    private const META = [
        ConsentRecord::TYPE_TERMS => ['title' => 'Общи условия', 'url' => '/legal/terms'],
        ConsentRecord::TYPE_PRIVACY => ['title' => 'Политика за поверителност', 'url' => '/legal/privacy'],
        ConsentRecord::TYPE_RISK => ['title' => 'Декларация за риска', 'url' => '/legal/risk'],
    ];

    /** Document consents the user still needs to (re-)accept. */
    public function pending(Request $request): JsonResponse
    {
        $versions = ConsentRecord::currentDocumentVersions();

        $pending = array_map(fn (string $type) => [
            'type' => $type,
            'version' => $versions[$type],
            ...self::META[$type],
        ], $request->user()->outstandingConsents());

        return response()->json(['pending' => array_values($pending)]);
    }

    /** Record fresh acceptance of the current document versions. */
    public function accept(Request $request): JsonResponse
    {
        $versions = ConsentRecord::currentDocumentVersions();

        $request->validate([
            'types' => ['required', 'array'],
            'types.*' => ['string', Rule::in(array_keys($versions))],
        ]);

        $user = $request->user();
        $outstanding = $user->outstandingConsents();

        // Every outstanding document must be accepted — partial acceptance leaves
        // the user gated and would be a confusing half-state.
        $missing = array_diff($outstanding, $request->input('types'));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'types' => ['Трябва да приемете всички обновени документи.'],
            ]);
        }

        foreach ($outstanding as $type) {
            $user->consentRecords()->create([
                'type' => $type,
                'version' => $versions[$type],
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'accepted_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Съгласието е записано.']);
    }
}
