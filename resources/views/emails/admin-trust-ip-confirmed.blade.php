<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <title>IP добавен в trusted</title>
    <style>
        body { font-family: Inter, system-ui, sans-serif; padding: 40px; color: #1B2A4A; }
        .card { max-width: 480px; margin: 0 auto; padding: 32px; border: 1px solid #e5e7eb; border-radius: 12px; }
        h1 { color: #22C55E; margin-top: 0; }
        code { background: #F8FAFC; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
<div class="card">
    <h1>✓ IP добавен в trusted</h1>
    <p>IP адресът <code>{{ $ip }}</code> вече е маркиран като trusted за admin акаунта <strong>{{ $admin->name }}</strong>.</p>
    <p>За в бъдеще логин-ите от този IP ще получават email с тема <code>[Admin Login - Known IP]</code> вместо предупреждението за нов IP.</p>
    <p><a href="{{ config('app.url') }}/admin">← Към admin панела</a></p>
</div>
</body>
</html>
