<?php

namespace App\Http\Controllers;

use App\Exceptions\DeletionLinkInvalidException;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * SEC-22 — the signed e-mail links of the account-deletion flow.
 *
 * GET renders a one-button page and changes NOTHING: mail gateways and link
 * scanners (Defender Safe Links, Proofpoint, Mimecast…) prefetch every URL in a
 * message, and the request mail carries BOTH links — a prefetched «не съм аз»
 * would cancel the request, kick every session and raise a false compromise
 * alert (review 2026-09-05). The action runs only on the POST from that page
 * (CSRF-protected web form; the signature travels in the form action URL, so
 * `signed` still guards the POST). No login is required on purpose — the
 * mailbox is the second factor. One generic landing for every failure — no
 * oracle on ids or hashes.
 */
class AccountDeletionLinkController extends Controller
{
    public function __construct(private AccountDeletionService $service) {}

    public function confirm(Request $request, int $user, string $hash): View|RedirectResponse
    {
        $account = $this->resolve($user, $hash);
        if ($account === null) {
            return $this->invalid();
        }

        if (! $request->isMethod('post')) {
            return view('links.confirm-action', [
                'title' => 'Потвърждение за закриване на акаунта',
                'text' => sprintf(
                    'Потвърждавате, че искате акаунтът %s да бъде закрит. Закриването се извършва %d дни след потвърждението; до тогава можете да се откажете от профила си или от линка «Не съм аз» в имейла.',
                    $this->maskedEmail((string) $account->email),
                    AccountDeletionService::waitingDays(),
                ),
                'button' => 'Потвърди закриването',
                'danger' => true,
                'action' => $request->fullUrl(),
            ]);
        }

        try {
            $this->service->confirm($account);
        } catch (DeletionLinkInvalidException) {
            return $this->invalid();
        }

        return redirect(config('app.url').'/profile?deletion=confirmed');
    }

    public function cancel(Request $request, int $user, string $hash): View|RedirectResponse
    {
        $account = $this->resolve($user, $hash);
        if ($account === null) {
            return $this->invalid();
        }

        if (! $request->isMethod('post')) {
            return view('links.confirm-action', [
                'title' => 'Не съм аз — отмяна на заявката',
                'text' => 'Ще отменим заявката за закриване на акаунта и ще прекратим всички активни сесии, токени и устройства за известия. След това сменете паролата си.',
                'button' => 'Отмени заявката и прекрати сесиите',
                'danger' => false,
                'action' => $request->fullUrl(),
            ]);
        }

        $this->service->cancel($account, 'link');

        return redirect(config('app.url').'/login?deletion=cancelled');
    }

    private function resolve(int $userId, string $hash): ?User
    {
        $account = User::where('id', $userId)->where('role', 'investor')->first();

        return $account !== null && $this->service->linkHashMatches($account, $hash) ? $account : null;
    }

    private function invalid(): RedirectResponse
    {
        return redirect(config('app.url').'/login?deletion=invalid');
    }

    private function maskedEmail(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false || $at === 0 ? '***' : mb_substr($email, 0, 1).'***'.substr($email, $at);
    }
}
