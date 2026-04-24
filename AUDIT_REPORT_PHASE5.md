# Phase 5 — Frontend Audit

**Branch:** `feature/phase5-frontend-audit` (from `main` at `a4492fe`)
**Base commit:** `a4492fe` (Phase 4 merged — audit-report + config/app.php timezone env())
**Session start:** 2026-04-24
**Scope:** Vue 3 SPA + Filament 3 admin UI correctness, accessibility, localization, performance, SEO, frontend-side security. NOT math (Phase 2 done), NOT backend security (Phase 1 done), NOT business logic (Phase 3 done), NOT infrastructure (Phase 4 done).

**Status:** 🟡 Step 0 complete — frontend inventoried, initial observations drafted. Steps 1–6 pending.

---

## Step table

| Step | Description | Status |
|---|---|---|
| 0 | Inventory — Vue components, Filament pages, routes, tests, build config. Initial observation scan. | ✅ |
| 1 | Vue component correctness — props validation, loading/error states, no console.logs, error boundaries | ⏸ |
| 2 | Form validation — client↔server parity, BG error messages, submit-disabled during pending, required indicators | ⏸ |
| 3 | Accessibility — ARIA, keyboard nav, contrast (WCAG AA), screen-reader | ⏸ |
| 4 | Performance — bundle size, re-renders, image opt, lazy loading | ⏸ |
| 5 | SEO + meta — per-route meta tags, sitemap.xml, robots.txt, OpenGraph/Twitter cards | ⏸ |
| 6 | Mobile + BG-localization + frontend security — breakpoints, touch targets, BG copy completeness, no secrets in bundle, CSP, v-html review | ⏸ |
| 7 | Findings + fixes + finalize | ⏸ |

---

## §1 Step 0 — Inventory

### 1.1 Investor SPA (Vue 3)

| Area | Count | Notes |
|---|---|---|
| Vue files | 28 total (3807 lines) | `resources/js/**/*.vue` |
| Views (route-mapped) | 15 | 10 authed app pages + 5 guest auth pages |
| Landing components | 11 | `components/landing/*` |
| Layouts | 2 | `App.vue` (router shell) + `AppLayout.vue` (sidebar shell) |
| Stores (Pinia) | 1 | `stores/auth.js` — login/fetchUser/register/logout |
| API clients | 1 | `api/axios.js` — CSRF + auto-Idempotency-Key on `/loans/*/invest` |
| Router | 1 | `router/index.js` — 14 routes, lazy-imports except HomePage |

**Tech stack** (`package.json`):
- Vue 3.5.30, Pinia 3.0.4, Vue Router 4.6.4
- Chart.js 4.5.1 + vue-chartjs 5.3.3
- Axios 1.15.0
- Tailwind CSS 4.2.2 + Vite 8.0.8
- laravel-vite-plugin 3.0.0

**Notable absences:**
- **No Vitest, no Cypress, no Playwright** — zero frontend test framework installed. Maps to HANDOFF_F4 §5 "No browser tests" residual.
- **No ESLint, no Prettier** — no automated linting or style enforcement.
- **No bundle analyser** — `rollup-plugin-visualizer` or similar not installed.
- **No i18n library** (`vue-i18n`, etc.) — Bulgarian copy is hardcoded in templates. Acceptable for single-locale app; cleanup opportunity if ever multi-locale.

### 1.2 Filament admin panel

| Group | Count | Notes |
|---|---|---|
| Pages (singletons) | 3 | `BuybackQueue` (F2), `FeesPage` (F4), `ProcessRepayment` |
| Resources | 10 | AuditLog, Borrower, DepositRequest, Loan, Originator, PlatformSetting, Transaction, User, WithdrawalRequest + associated CRUD/List/View pages |
| RelationManagers | 4 | AnonymizedProfile (borrower), AmortizationSchedules, Investments, LoanEvents (loan) |
| Widgets | 3 | InvestmentChart, LoanHealthOverview, StatsOverview |
| Blade partials | 6 | `filament/components/*`, `filament/modals/*`, `filament/pages/*` |
| Email templates | 6 | `emails/*.blade.php` |

Admin panel is entirely Filament (PHP-rendered server-side) — no Vue. Localization + accessibility posture differs from the SPA.

### 1.3 Routes map (investor SPA)

From `resources/js/router/index.js`:

```
/                      → HomePage               (eager)
/login                 → LoginPage              (guest only)
/register              → RegisterPage           (guest only)
/forgot-password       → ForgotPasswordPage     (guest only)
/reset-password/:token → ResetPasswordPage      (guest only)
/verify-email          → EmailVerificationPage  (any)

[AppLayout auth guard]
  /dashboard      → DashboardPage
  /deposit        → DepositPage
  /withdraw       → WithdrawalPage
  /invest         → MarketplacePage
  /invest/:id     → InvestmentDetailPage
  /portfolio      → PortfolioPage
  /transactions   → TransactionsPage
  /profile        → ProfilePage
```

Router guards: admin users auto-redirected to `/admin` Filament panel. Guest routes bounce authenticated users to dashboard. `fetchUser()` called once per page-load before first navigation.

### 1.4 HTML shell (`resources/views/app.blade.php`)

```html
<!DOCTYPE html>
<html lang="bg">       ← BG locale set
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..." rel="stylesheet">
  <title>P2P Invest</title>    ← single static title for all routes
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body><div id="app"></div></body>
</html>
```

**Present:** HTML lang attribute, viewport, CSRF meta, Inter font preconnect.
**Absent:** `<meta name="description">`, OpenGraph (`og:*`), Twitter cards, canonical link, explicit favicon link, route-aware title updates.

### 1.5 robots.txt vs docx expectation

`public/robots.txt`:
```
User-agent: *
Disallow:
```

`Disallow:` with empty value means **allow all paths** — not `Disallow: /` which `vamaasset-server-setup.docx` §10 and HANDOFF_PHASE4 claim is in place. This is a **contradiction between documented intent and actual file**.

**However,** nginx's `X-Robots-Tag: noindex, nofollow` response header (Phase 4 audit §2.3 P4-W1 observed in live response) DOES block indexing at the response-header layer. Net effect: site IS deindexed pre-launch, but via nginx header not robots.txt. Minor inconsistency, easily confused.

### 1.6 Backend routing hints

Blade views:
- `app.blade.php` — SPA shell
- `welcome.blade.php` — likely unused (Laravel default; worth confirming + removing)
- `filament/*` — Filament-specific
- `emails/*` — notification templates
- `vendor/mail/*` — Laravel mail layout (should be user-customised for brand consistency — currently default layouts, need to verify)

### 1.7 Public assets (`public/`)

```
robots.txt       ← Disallow: (empty — see 1.5)
favicon.ico      ← exists, browser fetches from root
index.php        ← Laravel entry point
.htaccess        ← Apache rewrite rules
css/filament/*   ← Filament published CSS
js/filament/*    ← Filament published JS
fonts/filament/* ← Filament Inter font WOFF2
```

**Absent:** `sitemap.xml`, `manifest.json` (PWA), `apple-touch-icon.png`, `og-image.png` / branded sharing image.

### 1.8 Console.log / v-html / debug statements

- `grep -r console\.(log|warn|error|debug|info)` against `resources/js/` → **zero matches**. Clean.
- `grep -r v-html` → **2 matches**, both rendering hardcoded SVG icon strings defined in the same component (`HowItWorks.vue:42`, `WhyUs.vue:46`). No user-input or API-sourced HTML.

### 1.9 Initial Bulgarian localization sample

From LoginPage.vue (representative):
- Labels "Имейл", "Парола", "Забравена парола?" ✓ BG
- Fallback error copy: `'Възникна грешка. Опитайте отново.'` ✓ BG
- Placeholder `"ime@example.com"` — ASCII transliterated (phonetic BG spelling of "име") — cosmetic, OK

From MarketplacePage.vue (representative):
- Loan types: "Потребителски", "Бизнес", "Ипотечен", "Мостов" ✓ BG
- Risk classes: "A — Нисък риск" through "E — Висок риск" ✓ BG

Localization completeness sweep will happen in Step 6 across all 15 views.

---

## §2a Decisions on initial observations (2026-04-24)

User + audit triaged the 5 initial observations as follows:

| # | Decision | Rationale |
|---|---|---|
| O1 (SEO meta tags) | **DEFER → pre-launch checklist** | Site intentionally deindexed pre-launch; SEO meta is irrelevant until launch imminent. Wasted effort if launch delayed. |
| O2 + O5 (robots.txt) | **FIX NOW (combined)** | 2-min effort, matches documented intent + defense-in-depth for `/admin` + `/filament`. |
| O3 (v-html SVG icons) | **No-op — document only** | Hardcoded static strings, zero XSS. Optional cosmetic refactor in v1.1. Not a finding. |
| O4 (zero frontend tests) | **DEFER → v1.1** | Legitimate gap, 1-2 days setup work. Documented as P5-L1 with specific v1.1 trigger. |

### Pre-launch checklist additions (from O1 decision)

When launch is imminent, execute in order:

1. `public/robots.txt` — remove `Disallow: /` line (keep `/admin` and `/filament`).
2. nginx `/etc/nginx/sites-available/vamaasset.bg` — remove `add_header X-Robots-Tag "noindex, nofollow" always;` line; reload nginx.
3. `resources/views/app.blade.php` — add `<meta name="description">`, `<meta name="keywords">`, canonical link, favicon explicit reference.
4. Add OpenGraph + Twitter Card meta tags (og:title, og:description, og:image, og:url, twitter:card=summary_large_image, twitter:site, etc.). Requires branded `og-image.png` (~1200x630) in `public/`.
5. Add route-aware dynamic `<title>` updates (via `vueuse/head` OR manual `watch` on `route.name` + `document.title = ...`).
6. Generate `public/sitemap.xml` — listing the public routes (/, /login, /register). Authenticated routes excluded.
7. Consider `manifest.json` (PWA) + `apple-touch-icon.png` — moderate priority.
8. Retest SSL Labs, Security Headers, Lighthouse SEO score — expect A+, A, 90+ respectively.

This checklist lives here until promoted to a dedicated `docs/runbooks/pre-launch-checklist.md` during Phase 4 Wave 4 work or v1.1.

### P5-L1 (LOW, accepted residual — v1.1 commitment) — No frontend test framework

The Vue codebase (3807 lines across 28 components) has **zero** automated tests. No Vitest, Cypress, or Playwright configured; no component tests, no integration tests, no e2e tests.

**Why accepted for v1:**
- Backend has strong coverage: 455 main + 175 audit = 630 tests with 3123 assertions (from HANDOFF_PHASE4 §3). Business logic is validated through the API layer.
- v1 launch scale: estimated 50–100 investors. Manual QA is tractable.
- Vitest setup + baseline coverage is 2 days of focused work — not realistic within Phase 5 scope.

**v1.1 trigger conditions** (bring forward if any of these):
1. Investor count crosses 200.
2. Any production frontend regression bug reaches a real investor.
3. First major refactor of Vue source (e.g. migration to `<script setup>` strict types, TypeScript adoption).

**Effort estimate for v1.1 commitment:**
- Day 1: Vitest + @vue/test-utils + @testing-library/vue install + config; write first 10 tests for `stores/auth.js` (login, fetchUser, logout, error paths) + `api/axios.js` (idempotency-key injection logic).
- Day 2: Component tests for `LoginPage`, `RegisterPage`, `MarketplacePage` (filter reactivity + loading states), `WithdrawalPage` (fee breakdown render), `InvestmentDetailPage` (APR null-safe render).

Total: ~2 working days, delivers ~30 tests covering the money-critical paths.

---

## §2 Initial observations (pre-Step 1)

Not yet formal findings — these are the first things to verify/confirm during Steps 1–6. If they survive closer inspection they become P5-F#.

### Observation O1 (likely MEDIUM) — SEO meta tags absent from HTML shell

`app.blade.php` has no `<meta name="description">`, no OpenGraph/Twitter card tags, and a static `<title>P2P Invest</title>` that does not update per route. Pre-launch this is moot (nginx noindex + docx-documented robots.txt intent). **Post-launch:** search engines need description + social platforms need OpenGraph for link previews. Single-static title hurts both SEO ranking signals and browser tab UX (all tabs say "P2P Invest").

Fix scope: router-level title + meta manager (`vueuse/head` or manual `document.title` watcher) + baseline OG tags in `app.blade.php`.

### Observation O2 (likely LOW) — robots.txt contradicts documented intent

`public/robots.txt` says `Disallow:` (allow all), while docx §10 + HANDOFF_PHASE4 say `Disallow: /`. nginx `X-Robots-Tag: noindex, nofollow` header is the real block mechanism, so net effect is still deindexed — but the file and docs diverge. Either fix the file to match docs, or update docs to state "noindex is enforced via nginx header, robots.txt is permissive by design."

### Observation O3 (likely LOW / false-positive) — v-html on SVG icons

Both occurrences (`HowItWorks.vue:42`, `WhyUs.vue:46`) render statically-defined SVG strings from the SAME component — no user input, no API data. **Zero XSS risk.** Flagged during inventory because `v-html` is a pattern-level scan target, not because of evidence. Cosmetic improvement only: inline SVG directly in `<template>` or extract into a Vue SVG component for consistency. Leaving as-is is acceptable.

### Observation O4 (likely MEDIUM) — No frontend test framework

Zero Vitest / Cypress / Playwright. All previous phase audits noted this as a residual (HANDOFF_F4 §5, etc.). 3807 lines of Vue have no automated coverage. For Phase 5 specifically, we'll rely on:
- Static analysis (grep, AST-ish pattern matches)
- Code review (read through all 28 components for manually verifiable properties)
- Manual browser QA at the end

Installing Vitest is out-of-scope for Phase 5 (would be its own multi-day effort with meaningful test writing). Will be flagged as LOW if discovered findings would have been caught by tests.

### Observation O5 (likely LOW) — robots.txt ALSO allows crawl of `/admin`

Not only is pre-launch indexing permissive-by-robots-txt, but if/when the noindex header goes away at launch AND robots.txt remains permissive, the Filament `/admin` panel would be discoverable. Should add `Disallow: /admin` and `Disallow: /filament` to robots.txt as defense-in-depth, regardless of launch timing.

---

## §2b Step 1 findings — Vue component correctness

Explore-agent sweep of all 28 Vue files surfaced 8 raw catch-related findings, triaged to **3 reportable + 1 pattern observation** after filtering out cases where silent catch is documented intent (CLAUDE.md F4-03) or intentional privacy boundary.

### Positive confirmations from Step 1

| Category | Verdict |
|---|---|
| 1. Props validation | ✓ Clean — landing components presentational; views use local `ref()` not props |
| 2. Loading states | ✓ Consistent `loading.value = true/false` pattern universally |
| 3. Error states | ⚠️ Findings below |
| 4. Hardcoded English UI strings | ✓ Zero instances (placeholder emails + `autocomplete` attrs excluded) |
| 5. Form client↔server alignment | ✓ HTML5 required/min/max + server 422 error display = defense in depth |

### P5-F1 (MEDIUM → fixed in-phase) — Silent favorite toggle

**File:** [resources/js/views/MarketplacePage.vue:74-82](resources/js/views/MarketplacePage.vue:74)
**Issue:** `toggleFavorite(loan)` swallowed all errors silently. Investor clicks heart, API rejects (auth, rate limit, network), heart doesn't update visually, no feedback.
**Fix:** added a `toastMessage` ref + `showToast(msg)` helper (4-second auto-dismiss, `role="alert"` `aria-live="polite"` for screen readers). On catch: `showToast('Неуспешно запазване на любим. Моля, опитайте отново.')`. Floating div rendered bottom-right when `toastMessage` truthy.

### P5-F2 (MEDIUM → fixed in-phase) — DepositPage blank on API failure

**File:** [resources/js/views/DepositPage.vue:24-37](resources/js/views/DepositPage.vue:24)
**Issue:** `Promise.all([api.get('/deposit'), api.get('/deposit/history')])` had only a `finally` — if either call failed, loading cleared but `depositInfo=null` + `deposits=[]` → user sees blank form with no error indication.
**Fix:** added `error = ref(null)` following the `DashboardPage.vue` pattern. On catch, `error.value = 'Грешка при зареждане на данните. Опитай да презаредиш страницата или се свържи с поддръжка.'`. Template renders `v-else-if="error"` block with red alert icon + "Опитай отново" retry button calling `load()`. Copy includes "опитай да презаредиш" action phrase per user request — gives user an action, not a dead-end error.

### P5-F3 (LOW → fixed in-phase) — Dead placeholder code in MarketplacePage

**File:** [resources/js/views/MarketplacePage.vue:110-117 (pre-fix)](resources/js/views/MarketplacePage.vue:110)
**Issue:** `onMounted` executed `api.get('/loans', { params: { per_page: 1 } })` with a comment "Extract unique originators from future endpoint; for now use loans" — data never used. Pure dead code making a wasted HTTP call on every marketplace visit.
**Fix:** removed the try/catch entirely; `onMounted(() => { loadLoans() })` remains. Future originator-filter implementation should use a proper `GET /originators` endpoint when added.

### P5-F4 (LOW → v1.1 + comments added in-phase) — Blanket catches pattern

Four locations use `catch { /* comment */ }` pattern where catch is silent:

| File:Line | Intent |
|---|---|
| `AppLayout.vue:23` | Notifications secondary UI — silent preserves main app flow |
| `WithdrawalPage.vue:56` | 403 expected for non-KYC users — graceful empty history |
| `WithdrawalPage.vue:63` | 403 expected for pre-KYC users — empty IBAN list acceptable |
| `InvestmentDetailPage.vue:103` | 403 for non-investors (privacy boundary) — hide timeline silently |

All are **intentional graceful degradation.** Current pattern risk: blanket catch swallows unexpected errors (500, network failure) too, not just expected 403s.

**Fix (in-phase):** added explicit `// Intentional: ...` comments at each catch site so the intent is obvious to future readers.

**Deferred to v1.1:** refactor pattern to differentiate expected vs unexpected errors:
```js
} catch (e) {
  if (e.response?.status !== 403) throw e  // re-throw unexpected
  // graceful degrade for expected 403
}
```
Effort: 30 min across 4 locations + any other similar patterns discovered later.

### Explicit non-findings (triaged out from raw sweep)

| File:Line | Why not a finding |
|---|---|
| `WithdrawalPage.vue:70` loadFeeConfig | **CLAUDE.md F4-03 documents** this exact graceful degradation |
| `InvestmentDetailPage.vue:103` loanEvents | Intentional 403 privacy boundary (captured in P5-F4 pattern) |

---

## §2c Step 2 findings — Accessibility + Localization

Explore-agent sweep against 5 a11y + l10n criteria produced 17 raw findings. Triaged to **5 consolidated findings + 2 explicit non-findings** after grouping pattern-level issues and verifying Bulgarian grammar.

### P5-F5 (MEDIUM → fixed in-phase) — Icon-only buttons lack `aria-label`

7 interactive icon-only elements across the SPA were unlabeled to screen readers. Consolidated into one finding with per-location Bulgarian labels. Dynamic labels used for state-bearing controls (favorites, FAQ expand) so screen-reader announcements reflect current state.

| File:Line | Control | Label |
|---|---|---|
| [AppLayout.vue:146](resources/js/layouts/AppLayout.vue:146) | Mobile hamburger | `"Отвори меню"` |
| [AppLayout.vue:160](resources/js/layouts/AppLayout.vue:160) | Notification bell | `"Известия"` |
| [AppLayout.vue:181](resources/js/layouts/AppLayout.vue:181) | Delete notification | `"Изтрий известие"` |
| [MarketplacePage.vue:264](resources/js/views/MarketplacePage.vue:264) | Favorite (desktop) | `:aria-label="loan._favorited ? 'Премахни от любими' : 'Добави в любими'"` |
| [MarketplacePage.vue:307](resources/js/views/MarketplacePage.vue:307) | Favorite (mobile card) | same dynamic |
| [LandingHeader.vue:28](resources/js/components/landing/LandingHeader.vue:28) | Mobile hamburger | `:aria-label="mobileMenuOpen ? 'Затвори меню' : 'Отвори меню'"` |
| [FaqSection.vue:53](resources/js/components/landing/FaqSection.vue:53) | FAQ expand chevron | `:aria-label="openFaq === i ? 'Свий въпроса' : 'Разгъни въпроса'"` + `:aria-expanded="openFaq === i"` |

### P5-F6 (MEDIUM → fixed in-phase) — Form error messages not announced to screen readers

16 error-message `<p>` tags across 6 form-bearing views used identical `class="mt-1 text-xs text-red-500"` but no ARIA semantics. Screen reader users saw no indication when validation failed.

**Fix:** batched `replace_all` across all 6 files: `class="mt-1 text-xs text-red-500"` → `role="alert" aria-live="polite" class="mt-1 text-xs text-red-500"`. Using `aria-live="polite"` (not `assertive`) per user direction — waits for reader pause instead of interrupting flow.

Files touched:
- `views/auth/LoginPage.vue` (2)
- `views/auth/RegisterPage.vue` (4)
- `views/auth/ForgotPasswordPage.vue` (1)
- `views/auth/ResetPasswordPage.vue` (2)
- `views/WithdrawalPage.vue` (3)
- `views/ProfilePage.vue` (4)

### P5-F7 (LOW → fixed in-phase) — Low-contrast text on white

3 occurrences of `text-gray-300/400` on light backgrounds that fail WCAG AA 4.5:1 for normal text:

| File:Line | Content | Before | After |
|---|---|---|---|
| `AppLayout.vue:154` | "свободни" label | `text-gray-400` | `text-gray-500` |
| `AppLayout.vue:176` | Notification timestamp | `text-gray-400` | `text-gray-500` (batch w/ P5-F8) |
| `AppLayout.vue:181` | Delete icon default color | `text-gray-300` | `text-gray-500` (batch w/ P5-F5) |
| `InvestmentDetailPage.vue:233` | APR null-value dash | `text-gray-300` | `text-gray-500` |
| `MarketplacePage.vue:250` | APR null-value dash | `text-gray-300` | `text-gray-500` |

Post-fix contrast ~4.6:1 (passes AA).

### P5-F8 (LOW → fixed in-phase) — Date formatter silently drops time options

[AppLayout.vue:179](resources/js/layouts/AppLayout.vue:179) used `toLocaleDateString('bg-BG', { day, month, year, hour, minute })` to render notification timestamps. **`toLocaleDateString` does not support `hour`/`minute` options** — those were silently ignored, so notifications only showed the date, never the time. This is a real display bug (independent of accessibility concerns).

**Fix:** changed to `toLocaleString(...)` which does honour `hour`/`minute`. Users now see e.g. `24.04.2026, 14:42` instead of just `24.04.2026`.

**Verified intentional (not findings):**
- `ProfilePage.vue:190` — `toLocaleDateString('bg-BG')` — date-only rendering of `auth.user.created_at` (registration date, time not shown by design).
- `InvestmentDetailPage.vue:83` — `toLocaleString('bg-BG', { dateStyle: 'medium', timeStyle: 'short' })` — correct usage.

### P5-F9 (LOW → fixed in-phase) — ChatbotWidget input lacks accessible label

[ChatbotWidget.vue:95](resources/js/components/ChatbotWidget.vue:95) had only `placeholder="Напишете въпрос..."`, which is not an accessible label (placeholders vanish on focus + aren't announced reliably by screen readers).

**Fix:** added `aria-label="Вашият въпрос"` to the input. Simpler than introducing a new DOM label node; same semantic effect.

### Explicit non-findings (Bulgarian grammar verification)

Explore agent flagged pluralization gaps that weren't actually wrong:

| File:Line | Agent claim | Why not a finding |
|---|---|---|
| `PortfolioPage.vue:149` "кредит"/"кредита" via ternary | "Bulgarian has 3 plural forms (1, 2-4, 5+)" | **Modern Bulgarian lost the dual number and is 2-form (singular/plural) for most nouns**, including "кредит" (masculine inanimate). The 1-vs-N ternary is grammatically correct. This is different from Russian which does have 3-form rules. |
| `InvestmentDetailPage.vue:337` `+{{ row.days_late }}д` | "Should be `дни` if > 1" | The "д" abbreviation is a stylistic choice (compact UI micro-label). Not a grammatical error. Could be `"дн."` for marginally more standard abbreviation but is within acceptable UI compression patterns. |

### Positive confirmations from Step 2

| Check | Verdict |
|---|---|
| Bulgarian locale for number formatting | ✓ `toLocaleString('bg-BG', { minimumFractionDigits: 2 })` used consistently everywhere |
| Currency symbol placement | ✓ All `{{ amount }} €` (symbol AFTER), never `€ {{ amount }}` |
| Form `<label>` + input `for`/`id` binding | ✓ Standard on all auth pages + profile + deposit + withdrawal forms |
| No hardcoded English UI strings | ✓ (confirmed again during this sweep — same result as Step 1) |

---

## §3 Audit plan for Steps 1–6

Mapped to user's 8 audit areas:

| Step | Areas covered | Primary approach |
|---|---|---|
| 1 | §1 Vue component correctness | Read all 15 views + 11 landing components; check props validation, loading/error patterns, error boundaries |
| 2 | §2 Form validation | Read all form-bearing views (Login/Register/ForgotPassword/ResetPassword/Deposit/Withdrawal/Invest/Profile); cross-reference server Form Requests for client-server rule parity |
| 3 | §3 Accessibility | ARIA/semantic check across 15 views; keyboard tab-order review; color-contrast sampling via Tailwind palette; screen-reader pass on critical flows (login, invest, deposit) |
| 4 | §4 Performance | Build analysis — `npm run build` + check dist sizes, lazy-loading router verified, image handling in views, Vue re-render pattern inspection |
| 5 | §5 SEO | app.blade.php gap analysis, robots.txt vs docs reconciliation, sitemap.xml decision (post-launch scope), OpenGraph/Twitter tag design |
| 6 | §6 Mobile + §7 Bulgarian + §8 Frontend security | Tailwind breakpoint sweep for sm/md/lg/xl coverage, touch target sizing, hardcoded-English grep (`'[A-Z][a-z]+ [A-Z][a-z]+'` ish), bundle-source-map search for secrets, CSRF token presence in forms, v-html final review |
| 7 | Findings + remediation | Findings table + fixes in-phase for low-effort items + defer list for v1.1 |

Expected final finding distribution (per user's prompt): 2-4 findings total, mostly LOW-MEDIUM, no CRITICAL.

---

## §4 Out-of-scope for Phase 5 (explicit)

To avoid scope creep, these are NOT Phase 5 work:

- **Installing Vitest / setting up frontend test framework** — multi-day effort of its own.
- **Dependabot npm alerts** (5 from docx §14) — out-of-scope code-hygiene issue, separate chore ticket.
- **Filament admin panel deep audit** — Filament's own opinions (a11y, mobile, etc.) are controlled by the package. Phase 5 will note major issues but not rewrite Filament views.
- **Redesign or visual changes** — Phase 5 verifies implementation matches intent, not re-designs.
- **i18n infrastructure for multi-locale** — single-locale (BG) design is acceptable for v1.
- **Strict CSP migration** (P4-W2 from Phase 4) — Vue + Filament compatibility work is v1.1.

---

## §5 Step 0 conclusion + sign-off request

**Inventory complete.** Frontend scope is clearly bounded: 15 Vue views + 11 landing components + 1 admin shell (Filament) + 1 HTML shell. Tech stack is current and well-chosen.

**Initial posture observation:** no CRITICAL smells surfaced in the inventory pass. Code hygiene is good (no console.logs, safe v-html usage, CSRF handled correctly in axios). Main gaps are SEO-surface (app.blade.php bare), robots.txt / docs divergence, and zero frontend test coverage.

**Recommendation:** proceed to Step 1 (Vue component correctness deep-read). Expected to surface 0-2 LOW-MEDIUM findings. Then Step 2 (forms), Step 3 (a11y), and so on.

---

*End of Phase 5 Step 0 Frontend Audit inventory. Step 1 pending user go-ahead.*
