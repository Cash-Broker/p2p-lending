# CLAUDE.md — Vamaasset P2P Lending Platform

> This file is deliberately **.gitignored** (audit C3/C4 untracked internal docs — commit `fcebc15`).
> Keep it local; never `git add -f` it. Update it when architecture-level facts change.
> Business-level documentation (BG, for the client) lives in `docs/BIZNES-DOKUMENTACIA.md` (tracked).

## Role

You are a senior fintech architect/developer. This is a REAL financial platform handling real
investor money. Production-grade only: no shortcuts, no "fix later", no demo code.
Rules that are always in force:

- **Tests after every task** — no exceptions. Behavior change ⇒ test change.
- **The client's (Reni's) word is law** — implement her explicit decisions literally; when
  something is ambiguous, ask; never substitute a "cleaner" alternative silently.
- Think end-to-end before coding: walk the full money flow before writing a line.
- Open product questions are **not bugs** — don't invent policy (see "Open product decisions").

## What this platform is

Virtual P2P / marketplace lending (Mintos/Bondora model), brand **Vamaasset** (ВАМА АСЕТ ЕООД,
ЕИК 201035515, vamaasset.bg). Investors fund loans originated by licensed originators.

- **All money stays inside the investor's platform profile** (virtual ledger). Deposits arrive
  by bank wire (admin confirms against a `DEP-XXXXXXXX` reference code); withdrawals are wired
  out by the admin externally. No PSP in the payout flow yet (ConnectPay offer pending).
  Deposit codes are **non-expiring** (client decision 2026-07-17, reverses audit M1 rotation):
  a pending code is retired ONLY by admin approve/reject; issuance is serialized per user
  (users-row `lockForUpdate` in `getOrCreateActiveCode`) + deterministic `latest('id')` pick.
- **Users:** Investor (individual or legal entity) and Admin (Filament). **Borrower is not a
  user** — data only: full `Borrower` (encrypted PII) + `BorrowerAnonymizedProfile` for investors.
- Investor picks one of **3 offers per loan** (payout structures at different rates); repayments
  flow back into the wallet. Everything in EUR.

## Tech stack (verified 2026-07-11)

- PHP ^8.3 (dev CLI runs 8.5), **Laravel 13**, **Filament 5** (^5.4, locked 5.6.x — NOT v3;
  see Filament idioms below), MySQL
- Sanctum 4 SPA cookie auth (`statefulApi()`, no bearer tokens)
- Vue 3.5 + Vite 8 + **Tailwind 4** (CSS-first, `@theme` in `resources/css/app.css`, no
  tailwind.config.js) + Pinia 3 + vue-router 4 + chart.js
- Pure **PHPUnit 12** (no Pest, no Dusk, no paratest), Vitest 4 for JS units
- spatie/laravel-csp (Report-Only), self-hosted Inter fonts (CSP `font-src 'self'`)
- barryvdh/laravel-dompdf ^3.1 (contract PDFs; bundled DejaVu Sans covers Cyrillic)

## Commands

```sh
composer test                                  # config:clear + ALL suites (incl. Audit!)
php artisan test --testsuite=Unit,Feature      # the "normal" run — Audit excluded
php artisan test --testsuite=Audit             # oracle-driven financial audit suite
                                               # (no defaultTestSuite in phpunit.xml — bare
                                               #  `php artisan test` also includes Audit)
php artisan test tests/Feature/RepaymentTest.php
php artisan test --filter=test_name
npm test                                       # vitest run (node env, *.test.js only)
npm run dev / npm run build                    # Vite
vendor/bin/pint                                # lint (default preset)
```

- Tests run against **real MySQL, database `p2p_lending_test`** (phpunit.xml). DB CHECKs and
  triggers are MySQL-only (skipped on sqlite) — sqlite would not exercise constraints.
- ⚠ Windows local: full-suite runs hit ~44 flaky `[2002] connection refused` failures
  (TIME_WAIT port exhaustion). Not regressions — run subsets for a clean signal.
- Audit oracle: `python audit/reference_calculator.py --generate` regenerates
  `audit/fixtures/phase2_cases.json` consumed by `tests/Audit/Phase2FinancialCorrectnessTest.php`
  (exact-string, zero-tolerance comparison vs Python Decimal).

## Architecture map

```
app/
  Models/              25 models; core: Loan, LoanOffer, Investment, InvestmentSchedule,
                       InvestmentContract (frozen agreement + click-wrap evidence),
                       AmortizationSchedule, Wallet, Transaction, LoanEvent, LoanGrant,
                       DepositRequest, WithdrawalRequest, Borrower(+AnonymizedProfile),
                       LegalEntityProfile, BeneficialOwner, ConsentRecord, SavedIban,
                       PlatformSetting, PlatformMetric, AuditLog, AdminTrustedIp
  Services/            ALL business logic. Money engine: WalletService (sole wallet gateway),
                       RepaymentService, ScheduledPayoutService, PayoutAccrualService,
                       OfferProjectionService, InvestmentScheduleGenerator, InvestmentService,
                       InvestmentContractService (dogovor snapshots + dompdf render),
                       AmortizationService, DepositService, WithdrawalService, FeeService,
                       APRCalculatorService, TelegramService, KycImageNormalizer,
                       AccountDeletionService
  Services/Loans/      InvestorDistributionService, Buyback{Calculation,Execution,Eligibility}Service,
                       EarlyRepayment{Calculation,Execution}Service, LateDetectionService,
                       LoanStatusUpdaterService
  Enums/PayoutType.php amortizing | interest_only | capitalized
  Support/             Money.php (string-decimal normalizer), BulgarianNumberWords.php
                       (сума/процент словом), CspPolicy.php, Loans/ScheduleBalanceValidator
  Filament/            Resources + Pages (BuybackQueue, FeesPage, ProcessRepayment) + Widgets
  Console/Commands/    ReconcileLedger; Loans/{ProcessLateLoans,DetectBuybackEligible,
                       ProcessScheduledPayouts,ReportPayoutExposure}; Ops/{TelegramDigest,TelegramTest}
  Http/                thin Api controllers → services; Form Requests; API Resources
  Policies/            7 policies (Loan, Investment, Wallet, Transaction, DepositRequest,
                       WithdrawalRequest, SavedIban)
  Rules/               ValidEgn, ValidEik, ValidVat, ValidIban
resources/js/          Vue SPA (views/, components/, stores/{auth,consent}, api/axios.js, router/)
audit/                 Python reference oracle + fixtures
scripts/ops/           local-backup.sh / local-restore.sh (AES-256 encrypted mysqldump)
docs/                  BIZNES-DOKUMENTACIA.md (BG business doc), runbooks/backup-restore-bg.md
```

Note: `DECISIONS.md` **no longer exists** — several docblocks still reference it (dangling).
Decision rationale now lives in git history + `docs/BIZNES-DOKUMENTACIA.md`.

## Financial core — invariants (CRITICAL)

- **Never float for money.** bcmath strings everywhere: scale 2 for money, 10 for rate
  intermediates, 12 for remainder ranking. DB `decimal(12,2)` (legacy `amortization_schedules`
  is 10,2). Input normalization via `App\Support\Money::normalizePositive`.
  (Known exception: `FeeService::getAmount()` float-round-trips the flat fee — tolerable only
  because of the 0–100 DB CHECK; don't copy the pattern.)
- **Every wallet move goes through `WalletService`** — row `lockForUpdate` → validate → mutate →
  immutable `Transaction` row (with ip/UA). Balances are never mass-assignable.
- **5 wallet buckets:** `available`, `reserved`, `invested`, `accrued`, `earned` — all DB CHECK
  ≥ 0. `currentBalance() = invested + accrued` ("Текущо салдо", derived).
  `reserve()`/`releaseReservation()` move buckets **without** a ledger row (hold, not event) —
  hence reconciliation compares `available+reserved` as one cash bucket.
- **14 transaction types** (`Transaction::TYPES`): deposit, withdrawal, investment,
  repayment_principal/interest, buyback_principal/interest, early_repayment_principal/interest,
  interest_accrued, interest_released, interest_accrual_reversed, fee, **bonus** (2026-08-09:
  admin promo credit «Начисли бонус» — primary entry: Депозити header action next to «Захрани
  сметка», user identified by ANY of their DEP codes (identification only, code NOT consumed);
  secondary: ViewUser header (no code needed). Shared guts `UserResource::grantBonus()` →
  `WalletService::bonus()` → available+; 2-min identical-grant replay guard; unique ref
  `bonus:admin:{id}:{uuid}`; reason ≤248 chars (255 − «Бонус: » prefix); NO bank wire behind
  it — bank-statement reconciliation must EXCLUDE `SUM(type='bonus')`, mirror of the fee note;
  investor gets «Бонус» tx + mail/bell; OTHER admins get a queued email per grant).
  `transactions`, `loan_events`, `audit_logs` are **immutable at the DB level** (MySQL triggers
  `SIGNAL SQLSTATE '45000'`); LoanEvent additionally throws from app-level `update()`/`delete()`
  (Transaction/AuditLog just set `UPDATED_AT = null` — the triggers are the guard).
- **No clamping — throw.** If invested would underflow, `WalletService` logs a
  `reconciliation_id` and throws `InvestedUnderflowException` (full rollback). Never mint money.
- **Conservation:** Σ(principal returned to a user over a loan's life) == their invested.
  Final installment / legacy buyback / early payoff pay each investor's EXACT ledger outstanding
  (computed by `InvestorDistributionService::outstandingPrincipalByUser` from ledger SUMs);
  offer-loan buyback pays Σ unpaid `investment_schedules.principal` per investment instead
  (numerically equivalent on consistent data).
  Non-final splits use `largestRemainderSplit` (Hamilton; Σ shares == amount exactly;
  tie-break ascending user_id). Legacy last-investor-remainder survives only for the _interest_
  part of buyback/early-repayment distributions.
- **Ledger references** (grouping key, format matters): `loan:{id}:user:{uid}` (repayment),
  `loan:{id}:investment:{iid}:schedule:{sid}`, `loan:{id}:investment:{iid}:capitalized`,
  `loan:{id}:investment:{iid}:buyback`, `loan:{id}:buyback:user:{uid}`,
  `loan:{id}:early_repayment:user:{uid}`, `investment:{id}`, `deposit_request:{id}`,
  `withdrawal_request:{id}` (+`:fee`), `bonus:admin:{admin_id}:{uuid}` (uuid = per-grant
  uniqueness; bonus has no backing entity row).
- **Idempotency:** invest = `idempotency_key` column (SPA auto-sends `X-Idempotency-Key`,
  backend 422s without it); repayment/payouts = `status='paid'` guard under row lock;
  buyback/early-repay = terminal-status check + dedicated exception; crons = `Cache::lock` 600s.
- **Money first, notifications after commit** — always. Notification failure logs, never rolls back.
- `ledger:reconcile` (daily 03:00, `--notify`) rebuilds expected buckets from `LEDGER_MAP`
  (default-deny: unmapped type = hard failure both directions). Adding a `Transaction::TYPE_*`
  ⇒ you MUST extend `LEDGER_MAP` or the command fails at startup.

## Security review wiring (MANDATORY)

Trigger: any change under app/Services/**, app/Support/Money.php,
app/Console/Commands/ReconcileLedger*, models Loan/Investment/Wallet/Transaction,
database/migrations/**, or Policies/.

Before presenting such a change as done:

1. Run the Trail of Bits web-app audit / vulnerability-detection skills on the
   diff and address findings.
2. Re-check "Financial core — invariants (CRITICAL)" — no rule may be weakened.
3. Changes to DB CHECKs, triggers, or LEDGER_MAP ⇒ stop for explicit human sign-off.

## Loan lifecycle

State machine `Loan::ALLOWED_TRANSITIONS` (guards in `booted()` + `transitionTo()`):

```
draft → published → funding → funded → active → late → default
          ↑______________|                ↕______|      |
        (draft only while funded_amount == 0)           ↓
active|late|default → repaid;  late|default → bought_back;  repaid/bought_back terminal
```

- `transitionTo()` wraps in DB::transaction; **funded → active generates schedules**
  (`InvestmentScheduleGenerator` for offer loans / `AmortizationService` for legacy) — that's
  why manual funded→active is excluded from the admin status Select
  (`MANUAL_STATUS_BLOCKLIST` also blocks repaid/bought_back).
- `IMMUTABLE_AFTER_DRAFT`: amount, investable_amount, rates, term, originator, borrower(s), type
  — model throws `LogicException`; `EditLoan::sanitizeSaveData()` strips them from Filament saves.
- **Orthogonal axes** (editable post-draft): `visibility` public/private (+ `share_token`,
  `loan_grants` — see Private links) and `payout_mode` manual/automatic.
- `fundingCap()` = outstanding principal when a schedule exists, else `investable_amount`
  (falls back to `amount`). Min investment 50 €.
- `LoanEvent` rows (append-only) are written only by **system-driven** lifecycle code:
  late/recovery/default transitions, auto-repay, buyback detection/execution, early repayment.
  Manual admin transitions (publish/activate/status Select) and funding transitions write NO
  LoanEvent — the audit trail for those is `audit_logs` via the Auditable trait. (Enum
  pre-expanded since F1; `fee_applied` still unused.)

## Dual schedule architecture (IMPORTANT)

Two coexisting repayment worlds, routed by `Loan::usesOffers()` (any investment with
`loan_offer_id`):

**1. Offer-based (current product — "3 оферти на кредит")**

- Every loan auto-seeds 3 `LoanOffer` rows on create (`PayoutType::defaults()`):
  Анюитет/amortizing 12% · Само лихва/interest_only 16% · Капитализация/capitalized 20%.
  Offers editable only in draft/published/funding (`LoanOffer::EDITABLE_STATUSES`, model-enforced).
- `Investment` **snapshots** `interest_rate` + `payout_type` at invest time — later offer edits
  never change existing investors' terms. Quote-vs-commit guard rejects stale-rate commits.
- Per-investment `investment_schedules` generated at activation (`OfferProjectionService` math:
  amortizing reuses `AmortizationService::calculateMonthlyPayment`; interest-only = flat monthly
  interest + principal at maturity; capitalized = single maturity row `P·(1+r)^n`).
- Payout: `PayoutAccrualService::processLoan()` — amortizing/interest-only release due rows
  straight to available; **capitalized accrues monthly into the `accrued` bucket**
  (delta toward compounded target) and releases everything at maturity.
- **The platform pays investors on schedule regardless of borrower payment** ("по график" —
  platform fronts the money). Exposure = `payouts:exposure` (Σ accrued + late/default loans in
  automatic mode). This is a deliberate, known product decision.
- Trigger: cron `loans:process-payouts` 04:00 for `payout_mode=automatic`; manual loans via the
  LoanResource action «Пусни плащане сега» → `ScheduledPayoutService::runForLoan()`.
- `InvestmentDisbursementService` is **orphaned** (test-only); production path is
  PayoutAccrualService.

**2. Legacy per-loan amortization (pre-offer loans, `loan_offer_id` null)**

- Per-loan `amortization_schedules`; admin posts installments via Filament «Погашения»
  (ProcessRepayment page) → `RepaymentService::processRepayment(loanId, scheduleId)`.
- **Schedule id required; amounts derived from the locked schedule row** — caller can never
  supply amounts (the old unbacked-mint path is closed). Duplicate guard: row `status='paid'`.
- Admin never types repayment amounts anywhere. The installment IS the amount.

## Buyback (F2) / Early repayment (F3) / Fees (F4) / APR (F5)

- **Buyback:** cron `loans:detect-buyback-eligible` 03:45 flags late/default loans past
  `originator.buyback_trigger_days ?? platform default 60` (explicit `??` — 0 is valid);
  admin executes from Filament «Buyback Queue» (Execute recomputes FRESH / Dismiss with reason /
  Reactivate). Coverage `principal_only | principal_plus_interest`
  (originator override ?? `buyback_default_coverage`). Offer-loan branch nets the accrued
  bucket: plus_interest ⇒ `releaseAccrued` (paid once), principal_only ⇒ `reverseAccrued`
  (**write-off** — this fixed the 2026-07-02 audit bug). `bought_back` is terminal.
- **Early repayment:** admin-only LoanResource action «Предсрочно погасяване», no cron.
  Schedule-boundary interest (unpaid interest up to next upcoming due date). **Legacy loans
  only — offer loans throw** `InvalidArgumentException` (not yet supported). Stamps
  `early_repaid_at` + `early_repayment_amount`, status → repaid.
- **Fees:** only `withdrawal` category wired; `fees_withdrawal_enabled` default **false**
  (flag off = byte-identical legacy behavior). Fee-on: two `debitReserved` calls
  (net TYPE_WITHDRAWAL + TYPE_FEE `…:fee`); net ≤ 0 throws. No platform wallet — fee revenue
  reconciles against the bank statement (`SUM(type='fee')`). Public `GET /api/fees/config`.
- **APR (ГПР):** `APRCalculatorService` = nominal pass-through of `interest_rate_annual`
  (exact while borrower-side fees are zero; IRR solver is the v1.1 upgrade). Null-safe → «—».
  Admin-only «Марж» = ГПР − Доходност in LoanResource «Ставки» section.

## Private loan links

`loans.visibility='private'` ⇒ unlisted; admin shares `/invest/shared/{share_token}` (48-char,
generated lazily by the «Линк за инвеститор» action). Opening the link grants a persistent
`loan_grants` row (needed — subsequent API calls carry no token). Access rule (single source of
truth `Loan::isAccessibleBy()`, used by `LoanPolicy::view`): admin OR public OR has investment
OR has grant. API route `/loans/shared/{token}` is registered BEFORE `/loans/{loan}`.

## Investment contracts («Договор за целеви паричен заем», 2026-08-09)

- Every OFFER-BASED invest concludes a loan agreement: `InvestmentContract` row created
  **inside** `InvestmentService::invest()`'s transaction (atomic — offer investment without
  contract must not exist; legacy null-offer path creates none). Investor = ЗАЕМОДАТЕЛ,
  platform company = ЗАЕМАТЕЛ (заемът е целеви → финансира „НАЗАЕМ.БГ“ ООД).
- **Click-wrap, NO signatures** (Reni 2026-08-09): the invest click IS the consent, recorded
  as `accepted_at`/`ip_address`/`user_agent` on the contract row + printed in the PDF as
  «Запис за електронно приемане». Individuals are identified by name + email only —
  **ЕГН/адрес deliberately not collected/printed** («засега без»); legal entities get ЕИК +
  seat + representative from `LegalEntityProfile` (decrypted at build).
- **Frozen snapshots, PDF on demand** (nothing on disk): `party_snapshot` (`encrypted:array`,
  PII) + `terms_snapshot` (plain JSON: amount/rate + «словом» via `App\Support\BulgarianNumberWords`,
  term, payout clause, projected schedule rows, company requisites from `contract_*`
  platform settings). `template_version` pins `resources/views/contracts/investment-v1.blade.php`
  — wording changes ⇒ NEW v2 template file, never edit v1. Schedule annex dates are
  indicative (real `investment_schedules` are generated at activation; the annex says so).
- Rendering: `InvestmentContractService` via barryvdh/laravel-dompdf (^3.1, DejaVu Sans =
  Cyrillic), `isRemoteEnabled/isPhpEnabled` false. Endpoints: investor
  `GET /api/investments/{id}/contract` (404 — not 403 — on foreign ids: no existence oracle)
  + `GET /api/loans/{loan}/contract-preview` («ПРОЕКТ» watermark, FUNDABLE_STATUSES only);
  admin `GET /admin/investment-contract/{investment}` (web route, isAdmin) + LoanResource
  InvestmentsRelationManager («Съгласие с договора» column + «Договор» action).
- Immutability: app-level only (`performUpdate`/`performDeleteOnModel` throw — covers
  save/saveQuietly/forceFill; query-builder/raw SQL NOT covered — DB trigger deferred
  pending sign-off). FKs are RESTRICT, not cascade (evidence must not silently vanish).
  Rows survive GDPR anonymization (Art. 17(3)(e) rationale in model docblock).
- SPA: consent wording + preview link in the confirm modal; `expected_interest_rate` now
  SENT on invest (quote-vs-commit guard armed; invest blocked until quotes load).
  Contracts exist only from the feature's ship date — **no backfill** (consent evidence
  can't be fabricated retroactively); `has_contract` gates the portfolio «Договор» link.

## KYC & compliance

- `users.kyc_status`: pending → submitted → in_review → approved/rejected (string column;
  `in_review` is app-level only). User flows set pending/submitted (ProfileController,
  AccountDeletionService reset); the review decisions (in_review/approved/rejected) come only
  from admin actions (`UserResource::kycStatusActions()` — transactional with from-state
  recheck). KYC submission notifies all admins via Filament DB-notification inbox (immediate
  insert, deliberately not queued) **+ event-driven EMAIL** (`KycSubmittedAdminNotification`,
  queued, mail-only; fires only on transition INTO the review queue — re-upload while already
  submitted/in_review refreshes docs + bell without re-emailing). Withdrawal creation mirrors
  the same pattern (`WithdrawalController::store` → sync bell + queued
  `WithdrawalRequestedAdminNotification`, no IBAN in the email). Deposits have NO event alert
  (wire lands at the bank, off-platform — nothing to hook); buyback has its own 03:45 cron
  email. All event alerts added 2026-08-07 ("когато има какво, без час").
- Upload `POST /api/profile/kyc` (throttle 6/1 + `consent.current`): `document_front`,
  `document_back`, `selfie` (plain upload — **live selfie capture was removed 2026-07-08**,
  client decision), `biometric_consent` (GDPR Art. 9 → ConsentRecord). Accepts
  jpg/jpeg/png/webp/heic/heif/pdf (selfie: no pdf; SVG excluded — XSS), max 10 MB.
- **HEIC pipeline** (`KycImageNormalizer`): capability check first (no libheif ⇒ 422), convert
  ALL files before the cache lock and any storage write — corrupt file ⇒ 422 + zero orphans.
  Stored on `local` disk under `kyc-documents/` (= `storage/app/private/`), served to admins
  only via `/admin/kyc-document/{path}` (isAdmin + path-traversal guards).
- Consents: versioned `ConsentRecord` (terms v1.1, privacy v1.1, risk v1.0, biometric v1.0);
  `consent.current` middleware forces re-accept via SPA `ReConsentModal` (403
  `consent_required` intercepted in axios).
- Legal-entity investors: `account_type`, `LegalEntityProfile` (encrypted ЕИК/ДДС/ЕГН),
  `BeneficialOwner` (UBO, PEP flag), validators ValidEgn/ValidEik/ValidVat.
- Encrypted-at-rest (`encrypted` cast): Borrower PII, IBANs (WithdrawalRequest, SavedIban),
  LegalEntityProfile ids, BeneficialOwner ids.
- GDPR deletion = anonymization (`AccountDeletionService`); blocked while investments active.

## Scheduler & ops

| Time (app tz — UTC unless prod .env sets `APP_TIMEZONE=Europe/Sofia`; the key is NOT in .env.example) | Command                           | Notes                                                                                                                    |
| ----------------------------------------------------------------------------------------------------- | --------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| 02:30 UTC (system cron)                                                                               | `/usr/local/bin/p2p-local-backup` | encrypted mysqldump, 30-day rotation; ⚠ possibly never installed on prod                                                 |
| 03:00                                                                                                 | `ledger:reconcile --notify`       | mismatch ⇒ email + "stop withdrawals"                                                                                    |
| 03:30                                                                                                 | `loans:process-late`              | F1: late detection + recovery + auto-repay; flags `--dry-run --loan= --detail --force`; kill switch `late_check_enabled` |
| 03:45                                                                                                 | `loans:detect-buyback-eligible`   | F2; same flags; kill switch `buyback_check_enabled`; must run after F1                                                   |
| 04:00                                                                                                 | `loans:process-payouts`           | offer payout engine, automatic loans only                                                                                |
| 09:00                                                                                                 | `telegram:digest`                 | BG morning digest (INFO tier, silent) + admin ACTION-ITEMS EMAIL (`AdminActionItemsNotification`, queued, only when KYC/deposits/withdrawals/buyback > 0, only to role=admin; independent of Telegram config). Since 2026-08-07 the digest email is a REMINDER backstop — the primary admin alerting is event-driven (see KYC section) |

- Health: `GET /api/health/scheduler` (public, 60/min) — F1 flat fields + nested `buyback`;
  worst-of excluding disabled; 503 iff critical (>48h). **Does NOT monitor the payouts cron.**
  `GET /up` = liveness. `payouts:exposure --record` is manual-only (not scheduled).
- Telegram (`TelegramService`): critical 🔴 / high 🟠 / info 🟡(silent); no-op if unconfigured;
  never throws. Uncaught exceptions mirror to CRITICAL (4xx/validation skipped).
- Queue: `database` connection, Supervisor `p2p-worker:*` on prod. Queued: password-reset job,
  admin-login-alert mail, the 4 loan-event notifications (with per-event dedupe in `via()`).
  Deposit/withdrawal/KYC/repayment notifications are synchronous.
- Prod: Hetzner, NGINX (`.htaccess` inert — vhost must carry Permissions-Policy & body limits;
  bit us with `camera=()` + 1M body limit). Unattended-upgrades restarts MySQL ⇒ short blips.
- Email: `.env.example` ships `MAIL_MAILER=log` (this dev machine currently runs smtp);
  `SEO_INDEXABLE=false` pre-launch (3-layer noindex).

## Filament v5 — project idioms (follow these, NOT v3/v4 habits)

1. `public static function form(Schema $form): Schema` / `infolist(Schema $infolist): Schema`
   — container type is `Filament\Schemas\Schema`.
2. Layout components from `Filament\Schemas\Components\*` (Section…); inputs stay
   `Filament\Forms\Components\*`; infolist entries `Filament\Infolists\Components\*`.
3. `Get`/`Set` live at `Filament\Schemas\Components\Utilities\Get` — `Forms\Get` doesn't exist.
4. ALL actions (table/header/page) from `Filament\Actions\*` — never `Tables\Actions\*`.
5. Non-static `protected string $view` on Pages; non-static `protected ?string $heading` on
   widgets; `navigationGroup` typed `string|UnitEnum|null`.
6. **Disabled fields are still validated AND dehydrated in v5** — frozen fields pair
   `->disabled(...)` with `->validatedWhenNotDehydrated(false)`, plus strip immutable keys in
   `mutateFormDataBeforeSave` (see `EditLoan::sanitizeSaveData`).
7. ⚠ `bg` locale can swallow validation error display — test admin forms in BG.
8. Encrypted columns can't be searched in SQL — Selects over Borrower use in-memory
   decrypt+filter (`getSearchResultsUsing`, latest 300).
9. Admin actions that move money/state: `DB::transaction` + `lockForUpdate` + status recheck +
   BG toast for every outcome; catch order specific → typed → `Throwable` (log + generic).
10. Panel: id `admin`, brand «Vamaasset Admin», `->databaseNotifications()`; nav groups
    «Финанси» / «Система»; access = `User::canAccessPanel()` (role admin).

## Frontend conventions

- **All UI copy Bulgarian**; code/comments/commits English; API errors English (frontend translates).
- Currency `toLocaleString('bg-BG')` + ` €`; dates `toLocaleDateString('bg-BG')` (DD.MM.YYYY).
  (Known debt: `formatAmount()` + label maps duplicated per view — no shared util/i18n.)
- Single axios instance `resources/js/api/axios.js`: `baseURL '/api'`, credentials + XSRF,
  auto `X-Idempotency-Key` on invest POSTs, 403 `consent_required` → re-consent modal.
- Router: no KYC gating client-side (server `kyc` middleware is authoritative; views render
  verification prompts from `auth.user.kyc_status`). Admins hard-redirect to `/admin`.
- Design: navy `#1B2A4A` primary, green `#22C55E` accent (Tailwind `@theme` palettes
  navy-_/accent-_), Inter self-hosted, `rounded-xl/2xl` cards, `bg-gray-50` background,
  loading spinners (`animate-spin` + «Зареждане», no skeletons) and empty states; BG toasts
  for every outcome in Filament, inline feedback in most SPA views.
- `ChatbotWidget.vue` is canned Q&A (no API); its fallback contacts are stale placeholders.

## Open product decisions — ask, don't invent

Pending with the client (Reni): default write-off policy, refund/reversal flow,
investor-APR semantics, offer-edit window, auto/manual-payout follow-ups (5 open questions),
and the fact that late/default loans in automatic mode keep paying investors on schedule.
Contracts: retention clock for `investment_contracts` PII after account anonymization
(currently indefinite, Art. 17(3)(e) basis); ЕГН/адрес collection for individuals was
declined 2026-08-09 («засега без») — revisit only if Reni asks.
Also platform-level: ConnectPay EMI integration (offer 2026-06-30) would change the fund flow.
If a task brushes against these, surface the question — do not encode an assumption.

## Known quirks / footguns

- `config('app.admin_email')` is not defined in config — ReconcileLedger always falls back to
  the hardcoded Gmail. Fix belongs in config + .env, not in the command.
- Six confirmed bugs from the 2026-07-02 audit were tracked; the buyback principal_only accrued
  leak IS fixed; verify the rest against `MEMORY` / audit notes before assuming.
- `envtest/` is a throwaway harness (backup-script fix proof) — candidate for deletion.
- No CI exists (no `.github/`); "CI suite" wording in phpunit.xml is aspirational.
- Docblocks referencing `DECISIONS.md` are dangling (file removed).
- Deposit codes can exist with `amount = NULL` (code issued before wire) — when counting
  "pending deposits" filter `where('amount', '>', 0)` (as TelegramDigest does; the admin UI
  uses `whereNotNull('amount')` for listing).
