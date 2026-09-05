<!doctype html>
{{-- One-button landing for signed e-mail links (SEC-01 / SEC-22): the GET that
     renders this page changes nothing — link scanners prefetch mail URLs — and
     the action runs only on the POST below (CSRF-protected). --}}
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} — Vamaasset</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f9fafb; font-family: Inter, -apple-system, "Segoe UI", Roboto, sans-serif; color: #1B2A4A; }
        main { width: 100%; max-width: 32rem; margin: 1.5rem; padding: 2rem; background: #fff; border-radius: 1rem; box-shadow: 0 10px 30px rgba(27, 42, 74, .08); }
        .brand { font-size: .8rem; letter-spacing: .12em; text-transform: uppercase; color: #6b7280; margin: 0 0 1rem; }
        h1 { font-size: 1.35rem; margin: 0 0 .75rem; }
        p { font-size: .95rem; line-height: 1.55; color: #374151; margin: 0 0 1.5rem; }
        button { width: 100%; padding: .85rem 1rem; border: 0; border-radius: .75rem; font-size: 1rem; font-weight: 600; color: #fff; cursor: pointer; }
        .primary { background: #1B2A4A; }
        .primary:hover { background: #142038; }
        .danger { background: #dc2626; }
        .danger:hover { background: #b91c1c; }
        .muted { font-size: .8rem; color: #6b7280; margin: 1.25rem 0 0; text-align: center; }
        .muted a { color: #1B2A4A; }
    </style>
</head>
<body>
    <main>
        <p class="brand">Vamaasset</p>
        <h1>{{ $title }}</h1>
        <p>{{ $text }}</p>
        <form method="POST" action="{{ $action }}">
            @csrf
            <button type="submit" class="{{ $danger ? 'danger' : 'primary' }}">{{ $button }}</button>
        </form>
        <p class="muted">Ако не сте отваряли този линк нарочно, просто затворете страницата — нищо не е променено. <a href="{{ config('app.url') }}">Към Vamaasset</a></p>
    </main>
</body>
</html>
