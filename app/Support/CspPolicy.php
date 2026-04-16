<?php

namespace App\Support;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Preset;

/**
 * Strict CSP for the P2P Lending platform.
 *
 * The platform deliberately has NO third-party dependencies served from the
 * browser — no Google Fonts, no Analytics, no CDN libraries, no payment
 * widgets, no Sentry. Everything ships from the same origin. The policy
 * therefore allows only `self` for almost every directive.
 *
 * Exceptions and their reasons:
 *  - `style-src` includes `unsafe-inline` because Filament and Tailwind v4
 *    inject inline <style> blocks at runtime (Filament's Livewire-driven
 *    UI is not nonce-friendly). This is the single intentional weakening.
 *  - `img-src` includes `data:` (Vue/Filament inline icons) and `blob:`
 *    (uploaded file previews before submission).
 *  - `frame-ancestors 'none'` plus `X-Frame-Options: DENY` (already set in
 *    SecurityHeaders middleware) double-locks against clickjacking.
 *
 * Deployment: registered in `report_only_presets` first (see config/csp.php).
 * Once production browser violations are zero for several days, swap to the
 * enforcing `presets` array. DO NOT enforce on day one — Filament admins
 * locked out of the panel = operations disaster.
 */
class CspPolicy implements Preset
{
    public function configure(Policy $policy): void
    {
        $policy
            ->add(Directive::DEFAULT, Keyword::SELF)
            ->add(Directive::SCRIPT, Keyword::SELF)
            ->add(Directive::STYLE, [Keyword::SELF, Keyword::UNSAFE_INLINE])
            ->add(Directive::IMG, [Keyword::SELF, 'data:', 'blob:'])
            ->add(Directive::FONT, Keyword::SELF)
            ->add(Directive::CONNECT, Keyword::SELF)
            ->add(Directive::FRAME_ANCESTORS, Keyword::NONE)
            ->add(Directive::BASE, Keyword::SELF)
            ->add(Directive::FORM_ACTION, Keyword::SELF)
            ->add(Directive::OBJECT, Keyword::NONE);
    }
}
