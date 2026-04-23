# Security Decisions Log

A running log of security-related architectural decisions, including
deferrals, trade-offs, and the compensating controls put in place.
Append new entries at the bottom; never edit prior entries (record
remediations as a new entry that references the old one).

---

## 2FA on Filament admin panel — DEFERRED to v1.1

- **Date:** 2026-04-16
- **Decision:** 2FA / MFA will not be implemented for the admin panel in v1.
- **Finding addressed:** [HIGH-1 in AUDIT_REPORT_PHASE1.md](AUDIT_REPORT_PHASE1.md)
- **Rationale:**
  - The admin team is currently very small (≤ 2 people).
  - Picking and integrating a 2FA flow (TOTP via filament-breezy / a custom
    Fortify integration / WebAuthn) cleanly with Filament 5.4 requires more
    than a one-line config change — schema migration, recovery codes,
    setup flow on first login, "trust this device" handling.
  - The blast radius of a compromised admin account is mitigated by the
    compensating controls below; a leaked password without the second
    channel will trigger an email alert that the admin can act on.
- **Compensating controls in place (v1):**
  - **Email alert on every admin login** (`42aa178`): Subject distinguishes
    known IP from new IP; new-IP emails carry a "trust this IP" signed link.
    Failed logins do not generate alerts (attackers cannot inbox-spam).
    Rate-limited so a legit burst of 11+ logins/hour from one IP collapses
    to two emails (initial + consolidated). Listener in
    `app/Listeners/SendAdminLoginAlert.php`.
  - **Strong password policy** enforced via `Password::defaults()` in
    `AppServiceProvider`: 8+ chars, mixed case, numbers, symbols.
  - **Audit logging** of every admin action via the `Auditable` trait on
    User, Wallet, Transaction, Investment, Loan, Borrower (all PII /
    financial models). PII fields redacted in stored audit values.
  - **Session hardening**: `SESSION_SECURE_COOKIE=true`, `SameSite=strict`,
    encrypted (`SESSION_ENCRYPT=true`), JSON serialisation (no PHP gadget
    chain risk).
  - **Login throttle** (5 attempts per email+IP per minute via
    `LoginRequest::ensureIsNotRateLimited`).
- **Trigger conditions for revisiting (any of):**
  - Admin team grows beyond 2 people.
  - Any external pen-test or auditor finding flags 2FA absence.
  - First reported security incident touching an admin account.
  - Before the v1.1 release, regardless of the above.
- **Owner of follow-up:** Backend lead.
- **Effort estimate:** 1-2 day spike to evaluate
  `stephenjude/filament-two-factor-authentication` vs. a Fortify-based
  build, then 2-3 days to ship including recovery codes, setup flow,
  and tests.

---

## CSP roll-out — Report-Only first, enforce after production soak

- **Date:** 2026-04-16
- **Decision:** Ship the CSP in `Content-Security-Policy-Report-Only`
  mode (commit `367d9c8`) instead of enforcing immediately.
- **Finding addressed:** [MED-4 in AUDIT_REPORT_PHASE1.md](AUDIT_REPORT_PHASE1.md)
- **Rationale:** A strict policy that breaks Filament's inline-script
  injection would lock admins out of the panel — operations disaster.
  Report-Only lets browsers log violations to console + `report_uri`
  (config: `CSP_REPORT_URI`) without breaking pages. Promotion to the
  enforcing `presets` array happens once production logs show zero
  legitimate violations across:
  - login + logout
  - Filament admin panel (every resource, every action)
  - Vue SPA navigation, dashboard, portfolio
  - KYC upload flow
  - investment + withdrawal flows
- **Promotion procedure:** Move `App\Support\CspPolicy::class` from
  `report_only_presets` to `presets` in `config/csp.php` and redeploy.
- **Owner of follow-up:** Frontend lead + backend lead jointly.
- **Trigger:** After 7 consecutive days of zero CSP violation reports
  in production logs.

---

## Admin role consolidation for v1 (Phase F1)

- **Date:** 2026-04-23
- **Decision:** All admin-side capabilities (Filament panel access,
  PlatformSettingResource read/write, loan operations, KYC approval,
  withdrawal approval, repayment posting) are gated on a single
  `users.role = 'admin'` value. No "super admin" / "support" / "viewer"
  separation in v1.
- **Why:** The Phase F1 spec asked for "Edit-ваемо за super admin" on
  the Settings page. We do not have a super-admin role in the schema,
  and adding one in F1 would have meant: a new role enum value, new
  policy methods on every Resource, role assignment UI, migration of
  existing admin users — all unrelated to the late-detection feature
  being built.
- **Compensating controls in place:**
  - Every PlatformSetting save creates an `audit_logs` row via the
    Auditable trait — who, what, when, IP, user-agent.
  - Email-on-admin-login alert (Phase 1 fix `42aa178`) — every Filament
    login fires an email with known/new IP distinction; settings changes
    by an unexpected admin are visible the same day.
  - Settings have minimal range — `grace_period_days ∈ 0..30` is enforced
    at form, model, AND DB CHECK levels; toggling `late_check_enabled`
    only pauses automation (manual override available via
    `php artisan loans:process-late --force`).
- **Trigger conditions for revisiting (any of):**
  - Admin team grows beyond 3 people.
  - Compliance asks for a "support read-only" role.
  - Before v1.1 release, regardless of the above.
- **Effort estimate:** 1 day (enum + migration + Resource gates) once
  the role taxonomy is decided.

---

## F2: default → bought_back transition allowed

- **Date:** 2026-04-23
- **Decision:** `Loan::ALLOWED_TRANSITIONS` permits both `late → bought_back`
  AND `default → bought_back`. `bought_back` itself is terminal (no outgoing
  transitions).
- **Rationale:** Manual admin model requires flexibility for late-stage
  originator recovery agreements. An admin may have transitioned a loan
  `late → default` (F1 limitation L1 — `default` is currently only reachable
  via admin Filament action), then weeks later the originator signs a
  buyback agreement. Forcing the admin to go `default → late → bought_back`
  would be artificial and would require extending the state machine with
  `default → late` (which doesn't make semantic sense). Instead, direct
  `default → bought_back` is a rare but valid path.
- **Compensating controls:**
  - The daily `loans:detect-buyback-eligible` cron flags loans in `late`
    AND `default` status (both are valid buyback candidates when they
    have a `became_late_at` history). `bought_back` is reached ONLY via
    admin-click Execute from the Buyback Queue — detection never
    transitions; only surfaces eligibility for admin review.
  - Every transition writes a `LoanEvent` row with `triggered_by='admin'`
    + `triggered_by_user_id` pinpointing the admin who executed.
  - Audit trail via `Auditable` trait on `Loan` captures the full diff.
- **Trigger conditions for revisiting:**
  - If the platform expands into markets where `default` status means
    legal recovery only (buyback impossible by regulation).
  - If compliance requires separating "delinquent" from "written off"
    semantics more strictly.
- **Owner of follow-up:** Backend lead (if the above conditions ever trigger).

---

## SEPA-only IBANs

- **Date:** 2026-04-16
- **Decision:** Reject IBANs from non-SEPA countries (US, AE, SA, TR, …)
  at validation rather than at the bank rails.
- **Finding addressed:** [MED-2 in AUDIT_REPORT_PHASE1.md](AUDIT_REPORT_PHASE1.md)
- **Rationale:** The platform settles in EUR over SEPA. A non-SEPA
  withdrawal is 99%+ either fraud (laundering routing) or a user error.
  Bank-side rejection wastes operational time and clutters forensics;
  failing fast at the API layer keeps the audit trail clean.
- **Trigger to revisit:** New regulated jurisdiction expansion (e.g.
  UK separately, US partnership), at which point the SEPA whitelist in
  `App\Rules\ValidIban::SEPA_COUNTRIES` should be revisited.

---
