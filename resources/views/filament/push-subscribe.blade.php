{{-- Admin Web Push enrolment (2026-08-17, Yordan: «когато засече, че админският
     акаунт е логнат, да му идват известия»). Injected at panels::body.end for
     AUTHENTICATED admin pages only (gated in AdminPanelProvider).

     Enrolment is ALWAYS behind a click: a «Включи известията» pill, never a
     timed browser prompt. An unexpected permission dialog earns a reflexive
     «Блокирай», and that verdict is permanent — a site cannot reset its own
     notification permission, only the user can (2026-08-17: the earlier 2s
     auto-prompt blocked Yordan's own Chrome). Safari and Firefox require a
     user gesture for the prompt anyway.
     While permission is granted, every panel load re-asserts the subscription
     so a new device starts flowing after a single click. --}}
@if (config('webpush.vapid.public_key'))
<script>
(function () {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;
    if (Notification.permission === 'denied') return;

    var VAPID = @js(config('webpush.vapid.public_key'));
    function b64ToU8(s) {
        var pad = '='.repeat((4 - (s.length % 4)) % 4);
        var raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    function xsrfToken() {
        var m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
        return m ? decodeURIComponent(m[1]) : null;
    }

    // A push tapped while the panel already shows that screen: refresh so the
    // admin sees the item the notification was about (review 2026-08-17).
    navigator.serviceWorker.addEventListener('message', function (event) {
        if (event.data && event.data.type === 'vama-push-refresh') window.location.reload();
    });

    // gesture = the admin just clicked/allowed → earns the «здравей» push.
    // The per-page-load re-assert passes false so it stays silent.
    function subscribe(gesture) {
        return navigator.serviceWorker.register('/sw.js').then(function () {
            // subscribe() needs an ACTIVE worker — `ready` guarantees one,
            // register() alone rejects with InvalidStateError on a first-ever
            // enrolment (review 2026-08-17).
            return navigator.serviceWorker.ready;
        }).then(function (reg) {
            return reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: b64ToU8(VAPID),
            });
        }).then(function (sub) {
            var json = sub.toJSON();
            return fetch('/api/push/subscribe', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken() || '',
                },
                body: JSON.stringify({
                    endpoint: json.endpoint,
                    keys: { p256dh: json.keys.p256dh, auth: json.keys.auth },
                    confirm: Boolean(gesture),
                }),
            }).then(function (res) {
                // Server refused to store it ⇒ drop the orphan browser
                // subscription; this device would never receive anything.
                if (!res.ok) return sub.unsubscribe().then(function () { throw new Error('registration failed'); });
            });
        });
    }

    function ask() {
        return Notification.requestPermission().then(function (p) {
            if (p === 'granted') return subscribe(true);
        });
    }

    function showPill() {
        if (document.getElementById('vama-push-pill')) return;

        var pill = document.createElement('button');
        pill.id = 'vama-push-pill';
        pill.type = 'button';
        pill.textContent = '🔔 Включи известията';
        pill.setAttribute('aria-label', 'Включи известията за това устройство');
        pill.style.cssText = 'position:fixed;right:1rem;bottom:1rem;z-index:9999;padding:.6rem 1rem;' +
            'border-radius:9999px;border:0;background:#1B2A4A;color:#fff;font:600 13px Inter,sans-serif;' +
            'box-shadow:0 6px 20px rgba(27,42,74,.28);cursor:pointer';

        pill.addEventListener('click', function () {
            pill.disabled = true;
            pill.textContent = 'Изчаква разрешение…';
            ask().then(function () {
                if (Notification.permission === 'granted') {
                    pill.textContent = '✓ Известията са включени';
                    setTimeout(function () { pill.remove(); }, 2500);
                } else {
                    pill.remove();
                }
            }).catch(function () {
                pill.textContent = 'Не се получи — опитай пак';
                pill.disabled = false;
            });
        });

        document.body.appendChild(pill);
    }

    if (Notification.permission === 'granted') {
        subscribe(false).catch(function () { /* transient — next panel load retries */ });

        return;
    }

    // permission === 'default' — ALWAYS via the pill, never a timed prompt.
    // The old 2s auto-prompt is why Yordan ended up blocked on 2026-08-17: an
    // unexpected browser dialog gets a reflexive «Блокирай», and that verdict
    // is permanent — a site cannot reset its own permission, only the user can
    // (catinarche → Известия). Our own UI must always come first.
    showPill();
})();
</script>
@endif
