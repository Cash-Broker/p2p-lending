<?php

namespace App\Http\Controllers;

use App\Models\SavedIban;
use App\Services\WithdrawalService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEC-01 (owner 2026-09-03): the signed e-mail link that confirms a newly
 * added payout IBAN. No login on purpose — mailbox possession IS the factor
 * (the attacker in this threat model already holds the password).
 *
 * GET renders a one-button page and writes NOTHING — mail gateways and link
 * scanners prefetch every URL in a message, and a prefetch must not confirm
 * an IBAN on the owner's behalf (review 2026-09-05). The confirmation runs on
 * the POST from that page (CSRF-protected; the signature travels in the form
 * action URL, so `signed` still guards the POST) and re-validates token and
 * expiry UNDER the row lock, so a resend that rotated the token wins the race.
 *
 * Every failure redirects to the SPA profile with `?iban=already|expired|invalid`
 * — one generic failure state for a missing row or a wrong token, no oracle.
 */
class SavedIbanConfirmationController extends Controller
{
    public function __invoke(Request $request, int $iban, string $token): View|RedirectResponse
    {
        $to = fn (string $state): RedirectResponse => redirect(config('app.url').'/profile?iban='.$state);

        $row = SavedIban::find($iban);
        if ($row === null) {
            return $to('invalid');
        }

        if ($row->isConfirmed()) {
            // Second visit of the same link: idempotent, nothing written.
            return $to('already');
        }

        if (! $row->matchesConfirmationToken($token)) {
            return $to('invalid');
        }

        if ($row->confirmationExpired()) {
            return $to('expired');
        }

        if (! $request->isMethod('post')) {
            return view('links.confirm-action', [
                'title' => 'Потвърждение на нов IBAN',
                'text' => sprintf(
                    'Потвърждавате IBAN %s като сметка за тегления по вашия профил във Vamaasset. Тегления към него са възможни %d ч. след потвърждаването.',
                    $row->maskedIban(),
                    WithdrawalService::newIbanCooldownHours(),
                ),
                'button' => 'Потвърди IBAN',
                'danger' => false,
                'action' => $request->fullUrl(),
            ]);
        }

        $outcome = DB::transaction(function () use ($row, $token): string {
            $locked = SavedIban::whereKey($row->id)->lockForUpdate()->first();
            if ($locked === null) {
                return 'invalid';
            }
            if ($locked->isConfirmed()) {
                return 'already';
            }
            // Re-validated under the lock: a resend that rotated the token between
            // the pre-check and here must make THIS link lose (TOCTOU).
            if (! $locked->matchesConfirmationToken($token)) {
                return 'invalid';
            }
            if ($locked->confirmationExpired()) {
                return 'expired';
            }

            // Auditable writes the `updated` row (ip/UA of the click); the hash is
            // redacted there and cleared here — the link cannot be replayed.
            $locked->forceFill([
                'confirmed_at' => now(),
                'confirmation_token_hash' => null,
                'confirmation_expires_at' => null,
            ])->save();

            return 'confirmed';
        });

        return $to($outcome);
    }
}
