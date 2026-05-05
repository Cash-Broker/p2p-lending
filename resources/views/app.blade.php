<!DOCTYPE html>
<html lang="bg-BG">
<head>
    {{--
        Defaults below are sane "homepage" values. Vue routes overwrite the
        title and description on navigation via the useDocumentMeta composable
        (see resources/js/composables/useDocumentMeta.js). Anything Vue can
        override via that composable should NOT have id attributes that
        collide here — the composable updates by name= / property= selector.
    --}}

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Vamaasset — P2P инвестиции в кредити</title>
    <meta name="description" content="Платформа за P2P инвестиции с достъп до кредити от утвърдени финансови институции. Прозрачност, диверсификация и контрол върху портфейла Ви.">
    <meta name="keywords" content="P2P инвестиции, инвестиране в кредити, peer-to-peer, инвестиционна платформа, доходност, България, Vamaasset">
    <meta name="author" content="ВАМА АСЕТ ЕООД">
    <meta name="generator" content="Laravel + Vue">
    <link rel="canonical" href="https://vamaasset.bg/">

    {{--
        Pre-launch crawler block. Defence-in-depth on top of:
          - public/robots.txt        (Disallow: /)
          - X-Robots-Tag HTTP header (SecurityHeaders middleware + .htaccess)
        At launch, set SEO_INDEXABLE=true in .env. The middleware will stop
        emitting the header and this meta tag will flip to "index, follow".
    --}}
    @if (config('app.seo_indexable'))
        <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
    @else
        <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    @endif

    {{-- Brand colours (used by mobile address bars + PWA splash) --}}
    <meta name="theme-color" content="#1B2A4A" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1B2A4A" media="(prefers-color-scheme: dark)">
    <meta name="color-scheme" content="light">
    <meta name="application-name" content="Vamaasset">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Vamaasset">
    <meta name="format-detection" content="telephone=no">

    {{-- Favicons & PWA manifest --}}
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">

    {{-- Open Graph / Facebook / LinkedIn --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Vamaasset">
    <meta property="og:locale" content="bg_BG">
    <meta property="og:url" content="https://vamaasset.bg/">
    <meta property="og:title" content="Vamaasset — P2P инвестиции в кредити">
    <meta property="og:description" content="Платформа за P2P инвестиции с достъп до кредити от утвърдени финансови институции. Прозрачност, диверсификация и контрол.">
    <meta property="og:image" content="https://vamaasset.bg/logo/logo.png">
    <meta property="og:image:width" content="1254">
    <meta property="og:image:height" content="1254">
    <meta property="og:image:alt" content="Лого на Vamaasset">

    {{-- Twitter Card --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Vamaasset — P2P инвестиции в кредити">
    <meta name="twitter:description" content="Платформа за P2P инвестиции с достъп до кредити от утвърдени финансови институции. Прозрачност, диверсификация и контрол.">
    <meta name="twitter:image" content="https://vamaasset.bg/logo/logo.png">
    <meta name="twitter:image:alt" content="Лого на Vamaasset">

    {{--
        JSON-LD: Organization schema. When launch happens, fill in
        address, telephone, email, sameAs (social profiles) so Google's
        Knowledge Panel has rich data to render.

        @verbatim is critical here — JSON-LD uses `@context` and `@type`
        which Blade would otherwise parse as directive calls (`@context`
        is a real Laravel directive since L11). Without the wrapper the
        compiled view dies with `unexpected end of file, expecting endif`.
    --}}
    @verbatim
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Organization",
        "name": "Vamaasset",
        "legalName": "ВАМА АСЕТ ЕООД",
        "url": "https://vamaasset.bg",
        "logo": "https://vamaasset.bg/logo/logo.png",
        "description": "Платформа за P2P инвестиции с достъп до кредити от утвърдени финансови институции.",
        "identifier": {
            "@type": "PropertyValue",
            "propertyID": "EIK",
            "value": "201035515"
        },
        "areaServed": "BG",
        "knowsLanguage": ["bg-BG"]
    }
    </script>
    @endverbatim

    {{-- Performance: preconnect to font origins (DNS + TLS handshake done early) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
