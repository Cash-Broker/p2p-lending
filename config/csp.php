<?php

// use Spatie\Csp\Directive;
// use Spatie\Csp\Keyword;

return [

    /*
     * Enforcing presets — empty during the report-only roll-out window.
     * Promote App\Support\CspPolicy here once the report-only deployment
     * has shown zero legitimate violations for several days.
     */
    'presets' => [
        //
    ],

    /**
     * Register additional global CSP directives here.
     */
    'directives' => [
        // [Directive::SCRIPT, [Keyword::UNSAFE_EVAL, Keyword::UNSAFE_INLINE]],
    ],

    /*
     * Report-Only preset — sends Content-Security-Policy-Report-Only so
     * browsers log violations without breaking pages. Tune the policy
     * (or app code) until violation reports are clean, THEN promote
     * CspPolicy into the `presets` array above for enforcement.
     */
    'report_only_presets' => [
        App\Support\CspPolicy::class,
    ],

    /**
     * Register additional global report-only CSP directives here.
     */
    'report_only_directives' => [
        // [Directive::SCRIPT, [Keyword::UNSAFE_EVAL, Keyword::UNSAFE_INLINE]],
    ],

    /*
     * All violations against a policy will be reported to this url.
     * A great service you could use for this is https://report-uri.com/
     */
    'report_uri' => env('CSP_REPORT_URI', ''),

    /*
     * Headers will only be added if this setting is set to true.
     */
    'enabled' => env('CSP_ENABLED', true),

    /**
     * Headers will be added when Vite is hot reloading.
     */
    'enabled_while_hot_reloading' => env('CSP_ENABLED_WHILE_HOT_RELOADING', false),

    /*
     * The class responsible for generating the nonces used in inline tags and headers.
     */
    'nonce_generator' => Spatie\Csp\Nonce\RandomString::class,

    /*
     * Set false to disable automatic nonce generation and handling.
     * This is useful when you want to use 'unsafe-inline' for scripts/styles
     * and cannot add inline nonces.
     * Note that this will make your CSP policy less secure.
     */
    'nonce_enabled' => env('CSP_NONCE_ENABLED', true),
];
