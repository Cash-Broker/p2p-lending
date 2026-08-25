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
                       DepositRequest, WithdrawalRequest, BonusGrant, Borrower(+AnonymizedProfile),
                       LegalEntityProfile, BeneficialOwner, ConsentRecord, SavedIban,
                       PlatformSetting, PlatformMetric, AuditLog, AdminTrustedIp
  Services/            ALL business logic. Money engine: WalletService (sole wallet gateway),
                       RepaymentService, ScheduledPayoutService, PayoutAccrualService,
                       OfferProjectionService, InvestmentScheduleGenerator, InvestmentService,
                       InvestmentContractService (dogovor snapshots + dompdf render), BonusService,
                       AmortizationService, DepositService, WithdrawalService, FeeService,
                       APRCalculatorService, PayoutLiabilityService (display-only: capital
                       still at work per plan; the users-page cards pair it with
                       AccruedEarningsService::accruedByPlan — «Текущо начислени
                       лихви» MUST equal Σ of each investor's «Текуща печалба»,
                       Reni 2026-08-19; the remaining SCHEDULE interest was
                       explicitly rejected as a headline),
                       TelegramService, KycImageNormalizer,
                       AccountDeletionService
  Services/Loans/      InvestorDistributionService, Buyback{Calculation,Execution,Eligibility}Service,
                       EarlyRepayment{Calculation,Execution}Service, LateDetectionService,
                       LoanStatusUpdaterService
  Enums/PayoutType.php amortizing | interest_only | capitalized
  Support/             Money.php (string-decimal normalizer), BulgarianNumberWords.php
                       (сума/процент словом), CspPolicy.php, Loans/ScheduleBalanceValidator
  Filament/            Resources (incl. the global read-only «Инвестиции» register with
                       live Sum summarizer — NOT the API InvestmentResource namespace!)
                       + Pages (BuybackQueue, FeesPage, ProcessRepayment) + Widgets
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
  A conditional bonus lives INSIDE `available` (investable from day one); only WITHDRAWING it
  is gated — `WalletService::withdrawableBalance()` = available − Σ locked grants.
  `reserve()`/`releaseReservation()` move buckets **without** a ledger row (hold, not event) —
  hence reconciliation compares `available+reserved` as one cash bucket.
- **17 transaction types** (`Transaction::TYPES`): deposit, withdrawal, investment,
  repayment_principal/interest, buyback_principal/interest, early_repayment_principal/interest,
  interest_accrued, interest_released, interest_accrual_reversed, fee, **bonus** (LEGACY —
  free-on-arrival grants made before 2026-08-18; never written again, kept mapped to cash so
  historical wallets still reconcile), **bonus_locked / bonus_released / bonus_cancelled**
  (see «Conditional bonuses» below). Admin entry points for a grant: Депозити header action
  next to «Захрани сметка» with a searchable investor picker (newest 50 preloaded, name/email
  SQL search — NO codes, boss 2026-08-10) + ViewUser header; shared guts
  `UserResource::grantBonus()` → `BonusService::grantAdminBonus()`; 2-min identical-grant
  replay guard; unique ref `bonus:admin:{id}:{uuid}`; reason ≤248 chars (255 − «Бонус: »
  prefix); NO bank wire behind it — bank-statement reconciliation must EXCLUDE
  `SUM(type IN ('bonus','bonus_locked','bonus_released'))`, mirror of the fee note; investor
  gets a tx + mail/bell/push; OTHER admins get a queued email per grant + a 🟡 silent Telegram
  record in the shared channel.
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
  uniqueness; the admin grant has no backing entity row at write time),
  `promo:{promo_id}:investment:{investment_id}`, `bonus_grant:{id}:release|cancel`.
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
- `IMMUTABLE_AFTER_DRAFT`: **NO LONGER ENFORCED** — reversed by EXPLICIT client decision
  2026-08-10 (Reni, twice, incl. invested loans): every loan field + the offers edit in EVERY
  status. Committed investors stay protected by Investment snapshots + frozen contracts +
  quote-vs-commit guard + `chk_loans_funded_amount_valid` (amount can't drop below funded) +
  the status machine / `MANUAL_STATUS_BLOCKLIST` (money-moving transitions still gated).
  A Filament warning banner shows on invested loans (`Loan::isTermsEditable()` = has no
  investor money; also gates DELETION eligibility). `EditLoan::sanitizeSaveData()` now only
  normalizes coerced empties ('' → null) + keeps published_at consistent. ⚠ Known accepted
  risk: term_months edits on a FUNDING loan change schedules generated at activation while
  contract annexes show the old term.
- Loan deletion (2026-08-10): list row + bulk actions, ONLY draft + funded==0 + no
  investments (`LoanResource::isDeletableLoan`); bulk skips ineligible rows with a count.
- Borrower creation (2026-08-10): «Кредитен рейтинг» is the letter scale A/B/C
  (`Borrower::CREDIT_RATINGS`, credit_score column is a string now); both creation forms
  (BorrowerResource + inline from the loan form) collect the ANONYMIZED investor profile
  (risk class/region/purpose required) so «Неопределен» placeholders never reach investors;
  `ensureAnonymizedProfile(array $attributes)` merges real values over the fallbacks.
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

⚠ Prod check 2026-08-18: **zero live legacy loans** (none in active/late/default). This branch
now serves history only — do not invest effort in porting new features to it (the early-closure
work deliberately stopped at the offer engine for exactly this reason).

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
- **Early repayment — LEGACY loans:** admin-only LoanResource action «Предсрочно погасяване»,
  no cron. Schedule-boundary interest (unpaid interest up to next upcoming due date). Stamps
  `early_repaid_at` + `early_repayment_amount`, status → repaid. The action now HIDES itself on
  offer loans (they get the closure buttons below), so the old "not supported" error is
  unreachable.
- **Early closure — OFFER loans, full or PARTIAL (Reni 2026-08-18):**
  `EarlyClosure{Calculation,Execution}Service`, admin-only, no cron. Two buttons in the loan
  page's bottom cluster next to «Запази»/«Линк за инвеститор» (Yordan: «като влиза в кредита
  там да ги има») + the same two in the list row actions: «Предсрочно погасяване» (whole loan)
  and «Частично погасяване» (amount + as-of date, `maxDate` today — interest is never priced
  into the future).
  - Rules, verbatim from Reni: the closed share is the SAME for every investor, applied to
    their own outstanding («на всеки по 40% от неговата позиция»); the position **shrinks
    pro-rata over the remaining installments and the term does NOT move**; interest is for the
    days actually used at **30/360** (`App\Support\DayCount`; she picked the basis after being
    shown actual/365); capitalized pays «натрупаното до деня» (whole months compounded + stub
    days, capped at the contracted maturity interest) and the rest shrinks; unlimited repeats,
    no minimum.
  - Cancelled installments get schedule status **`closed`** (+ `closed_at`), never `paid`:
    nothing was received, and `paid` would both lie in the portfolio and unlock a conditional
    bonus, which Reni excluded. `LoanStatusUpdaterService` treats `closed` as settled for
    auto-close; the payout services already filter on pending/late.
  - ⚠ `PayoutAccrualService::processCapitalized` now reads `$row->principal` instead of
    `$investment->amount` — otherwise a shrunken position keeps accruing on the original
    principal and pays it out twice at maturity.
  - `loan_early_closures` = one row per event (ratio, amounts, as-of, admin) — the only place
    the inputs survive, since the closure rewrites the schedules it was computed from. Money
    rides the existing `early_repayment_principal/interest` types, so **`LEDGER_MAP` is
    untouched**. Investor gets `EarlyRepaymentReceivedNotification` (full) or
    `LoanPartiallyClosedNotification` (partial, deduped on `closure_id` — a partial closure has
    no `early_repaid_at` to key on and may repeat the same day).
- **Fees:** only `withdrawal` category wired; `fees_withdrawal_enabled` default **false**
  (flag off = byte-identical legacy behavior). Fee-on: two `debitReserved` calls
  (net TYPE_WITHDRAWAL + TYPE_FEE `…:fee`); net ≤ 0 throws. No platform wallet — fee revenue
  reconciles against the bank statement (`SUM(type='fee')`). Public `GET /api/fees/config`.
- **APR (ГПР):** `APRCalculatorService` = nominal pass-through of `interest_rate_annual`
  (exact while borrower-side fees are zero; IRR solver is the v1.1 upgrade). Null-safe → «—».
  Admin-only «Марж» = ГПР − Доходност in LoanResource «Ставки» section.

## Conditional bonuses (Reni 2026-08-18)

Every bonus — admin «Начисли бонус» AND flash-promo — is granted **locked** and becomes
spendable only when the investor has money genuinely working in the platform. Rule:

> Σ (investments made after the grant, on which the investor has already RECEIVED
> `required_installments` scheduled payments) ≥ `base_amount`

- ⚠ **Two designs shipped the same day.** v1 (commit 494352c) parked the money in a
  `wallets.bonus_locked` bucket; Reni then asked whether the bonus can be invested while it
  waits («или ще стои заключен без движение до 3-тия месец») ⇒ v2: the grant credits
  `available` (`TYPE_BONUS_LOCKED`, cash-in) and the condition is a **withdrawal floor** in
  `WalletService::reserve()` — its only caller is `WithdrawalService::createRequest`, so
  investing is untouched and cashing out cannot reach an unearned bonus. Migration
  `2026_08_18_000002` moves the bucket into `available` and drops the column; `TYPE_BONUS_RELEASED`
  survives ONLY as a mapped-to-zero legacy type (releasing now writes no ledger row — the money
  never moves, the floor drops). Write-off = `TYPE_BONUS_CANCELLED` (debits the balance; refuses
  when the investor already spent the bonus).
- `bonus_grants` row per grant carries the terms: amount, `base_amount` (admin types it in;
  = the investment amount for promo grants), `required_installments` (default 3), `source`
  (admin|promo), `qualifies_from`, status, and the release/cancel evidence. DB CHECK pins
  status/source and the released_at ↔ release_transaction_id pair.
- **Client decisions encoded:** all bonuses (not just manual); the base may be spread over
  SEVERAL loans (the sum counts); «след третия падеж» = three RECEIVED payouts; a plan with
  fewer rows than required (capitalized pays once, at maturity) unlocks on its last one; NO
  claim button — «важното е да се изпълнят условията, тегленето си е теглене».
- ⚠ Prod check 2026-08-18: all 7 live loans are `payout_mode=automatic`, so «three RECEIVED
  payouts» vs «three elapsed due dates» differ by 15 minutes (04:00 cron marks paid, 04:15
  releases). The distinction only becomes visible if a MANUAL-payout loan ever exists — then
  the bonus waits for the admin's «Пусни плащане сега» click. Not worth a client question until
  that happens.
- **Assumptions to confirm if she ever asks:** only investments made AFTER the grant count
  (`qualifies_from`; for promo grants it is the investment's own timestamp, so its investment
  qualifies); partial fulfilment releases nothing; grants never expire; grandfathered `bonus`
  rows stay free.
- Release path: `BonusService::evaluateUser()` from the `bonuses:release-eligible` cron (04:15,
  after the payout cron marks installments paid). Idempotent — grant row `lockForUpdate` +
  status recheck. Admin can cancel a LOCKED grant from Filament «Финанси → Бонуси» (read-only
  register + cancel action); released money is the investor's and is not reversible there.
- Account closure forfeits locked grants (`TYPE_BONUS_CANCELLED`) **before** the balance
  checks — the bonus sits inside `available` and is not withdrawable, so checking first would
  leave the investor unable to ever empty the account.
- Investor UI: `wallet.withdrawable` (≠ `available` exactly by the unearned bonuses) + a
  `locked_bonus` block in `/api/dashboard` (amount, base, qualified so far, remaining) →
  `LockedBonusStrip.vue`; the withdrawal screen shows the withdrawable figure and names the
  difference. The grant mail spells the condition out — the money is spendable from that
  moment, so that is when we must say what it takes to CASH it.

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
  **New investor registration** joins them 2026-08-20 (Reni: «за нови регистрации на
  инвеститори може ли да получавам известия»): `SendInvestorRegisteredAlert` on the
  `Registered` event → sync bell + queued `InvestorRegisteredAdminNotification`
  (mail + Web Push, no name on the lockscreen) + 🟡 Telegram. ⚠ It is the FIRST admin
  alert a stranger can trigger — `/api/register` is public — so the listener counts the
  investor rows of the rolling hour and past the 11th sends ONE «повишен брой
  регистрации» summary (🟠 Telegram) and then stays silent for the rest of the hour,
  bell included; the Users list + 09:00 digest stay the complete record. The window is
  counted from `users.created_at`, NOT a cache counter — `Cache::increment` on a missing
  key returns false with the database store, the exact bug that left the admin-login
  alert dead in prod.
- **Телефон задължителен при регистрация** (Reni 2026-08-25, ДВАТА типа акаунт):
  `phone` е в base rules на `RegisterRequest` + нов `App\Rules\ValidPhone`
  (permissive intl: +/( в произволен ред, цифри+разделители, 6–15 цифри);
  `UpdateProfileRequest` също го изисква (не може да се изтрие), а `name` там е
  `sometimes` — модалът праща САМО phone (ко-submit на legacy име, падащо на
  control-char regex-а от 2026-08-07, би заключил акаунта завинаги). Заварени
  акаунти без телефон: блокиращ `PhoneRequiredModal` в AppLayout — gate
  `utils/phoneGate.js` (unit-tested), показва се САМО на verified инвеститори
  (PUT /profile е зад `investor` middleware, иначе 403 dead-end); чака
  consent-пробата (`consentChecked`) и consent-модалът има приоритет; фонът е
  `inert` (иначе Tab минава зад overlay-а), има «Изход» (блокиран е ДОСТЪПЪТ,
  не сесията — на shared устройство изходът трябва да остане). Админ recovery:
  `UserResource::phoneAction()` («Редактирай телефон» на ViewUser, същият
  ValidPhone). `users.phone` остава nullable в DB — пълни се през модала, НЕ
  през миграция. ⚠ Enforcement за заварени акаунти е САМО клиентски (директно
  API извикване минава без телефон) — вярно на буквалната заявка; сървърен
  gate = отворен въпрос за Рени, не го добавяй мълчаливо.
- Upload `POST /api/profile/kyc` (throttle 6/1 + `consent.current`): `document_front`,
  `document_back`, `selfie` (plain upload — **live selfie capture was removed 2026-07-08**,
  client decision), `biometric_consent` (GDPR Art. 9 → ConsentRecord). Accepts
  jpg/jpeg/png/webp/heic/heif/pdf (selfie: no pdf; SVG excluded — XSS), max 10 MB.
- **HEIC pipeline** (`KycImageNormalizer`): capability check first (no libheif ⇒ 422), convert
  ALL files before the cache lock and any storage write — corrupt file ⇒ 422 + zero orphans.
  Stored on `local` disk under `kyc-documents/` (= `storage/app/private/`), served to admins
  only via `/admin/kyc-document/{path}` (isAdmin + path-traversal guards).
- Consents: versioned `ConsentRecord` (terms **v1.2** since 2026-08-18 — added чл. 5.4
  «Предсрочно погасяване» + чл. 7 «Промоционални бонуси», so the bonus-release rule is a
  contractual term and not just an email; privacy v1.1, risk v1.0, biometric v1.0);
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
| 04:15                                                                                                 | `bonuses:release-eligible`        | conditional bonuses whose condition now holds (flags `--dry-run --user=`); runs after the payout cron marks installments paid |
| 09:00                                                                                                 | `telegram:digest`                 | BG morning digest (INFO tier, silent) + admin ACTION-ITEMS EMAIL (`AdminActionItemsNotification`, queued, only when KYC/deposits/withdrawals/buyback > 0, only to role=admin; independent of Telegram config). Since 2026-08-07 the digest email is a REMINDER backstop — the primary admin alerting is event-driven (see KYC section) |

- Health: `GET /api/health/scheduler` (public, 60/min) — F1 flat fields + nested `buyback`
  + nested `payouts` (2026-08-17: the 04:00 money cron writes `last_payouts_*` metrics and
  is ALWAYS in the worst-of — no kill switch; deploy of that release must run
  `php artisan loans:process-payouts` once or the missing metric reads critical);
  worst-of excluding disabled toggles; 503 iff critical (>48h).
  `GET /up` = liveness. `payouts:exposure --record` is STILL manual-only (not scheduled) —
  open item: schedule + threshold alert pending Reni's праг decision.
- Telegram (`TelegramService`): critical 🔴 / high 🟠 / info 🟡(silent); no-op if unconfigured;
  never throws. Uncaught exceptions mirror to CRITICAL (4xx/validation skipped).
- Queue: `database` connection, Supervisor `p2p-worker:*` on prod. Queued: password-reset job,
  admin-login-alert mail, the 4 loan-event notifications (with per-event dedupe in `via()`).
  Deposit/withdrawal/KYC/repayment notifications are synchronous.
- **Web Push (2026-08-17)**: `laravel-notification-channels/webpush`, VAPID keys in .env
  (Windows dev: `webpush:vapid` fails on EC keygen — use `npx web-push generate-vapid-keys`).
  `public/sw.js` is push-ONLY (⚠ never add fetch/caching — stale-bundle hazard).
  - **Delivery is QUEUED, never inline**: notifications list
    `App\Notifications\Channels\QueuedWebPushChannel` (NOT the package's channel) which renders
    the payload and dispatches `Jobs\DeliverWebPushNotification` per device. Reason: senders run
    inside DB transactions holding row locks (`UserResource::transitionKycStatus` under a `users`
    `lockForUpdate`) — a synchronous push held the lock across a Google round-trip AND a throw
    from the push lib rolled the KYC approval back after the investor's email had gone out. The
    channel NEVER throws; the job prunes dead (404/410) and unusable subscriptions.
  - Subscriptions per user via `POST/DELETE /api/push/subscribe` (plain auth:sanctum, throttle
    30/1 — NOT in the investor group; Filament admin sessions use the same endpoints). Validation
    is deliberately strict: endpoint host must be a KNOWN push service (the server later POSTs
    there — otherwise it's an outbound-request primitive), max 500 = column size, p256dh/auth
    must be base64url decoding to 65/16 bytes, max 10 devices/user.
  - Enrolment: SPA Профил card + dashboard banner; admin auto-prompt render hook (one prompt per
    browser remembered in localStorage + a persistent pill for Safari/Firefox which need a
    gesture). **One device can serve SEVERAL accounts**: uniqueness is (endpoint + owner), not the
    package's global `endpoint` unique — Reni runs the admin panel AND her investor profile in the
    same browser and both streams must arrive. Use `User::registerPushSubscription()`, never the
    package's `updatePushSubscription()` (it DELETES the other account's row). Each side
    re-registers its own row on load (`assertOwnership()` in the SPA, per-panel-load in Filament);
    logout revokes only that account's row.
  - Lockscreen hygiene: NO investor names in admin pushes, no IBANs, and NO admin free-text
    (`reason`) anywhere — details live behind auth.
  - Morning digest `push:payout-digest` 09:05 (kill switch `push_payout_digest_enabled`), 24h
    window, **only payout-engine references** (`loan:%:investment:%`) — legacy repayment/buyback/
    early-repayment push instantly on their own, so counting them here double-announced euros.
  - GDPR: `AccountDeletionService` deletes push subscriptions (endpoint = personal data).
  - ⚠ **NEVER trigger the browser permission prompt without a user click** — permission is
    per-ORIGIN and a reflexive «Блокирай» is permanent (no API can reset it; only the user via
    site settings). An auto-prompt in the admin panel blocked vamaasset.bg for Yordan's whole
    browser, investor SPA included (2026-08-17). Both surfaces now ask only behind a click, and
    the `denied` state explains how to unblock instead of hiding.
  - Investor opt-in: `PushOptInBanner` on the dashboard ASKS (Reni/Yordan 2026-08-17 — the
    Профил card alone is passive); it is a deliberately UNDERSTATED one-line strip (small gray
    text + inline «включи» link + ✕), not a boxed CTA. «Не сега» snoozes 30 days
    (`utils/pushPrompt.js`, which also drives the `denied` and iOS-install hints). A newly
    registered device gets a push-only `PushEnabledNotification` («здравей») so the person sees
    delivery works; re-asserts don't re-send it.
  - `DeliverWebPushNotification` deletes a subscription ONLY on `InvalidArgumentException` (that
    device's keys are unusable). Every other failure RETHROWS — a bad VAPID pair used to mass-
    unsubscribe everyone (caught by tests 2026-08-17). Do not widen that catch.
  - **Deploy order (prod has 3 caches — burned us twice on 2026-08-17: empty table name, then
    405 on the new routes)**:
    `git pull && composer install --no-dev --optimize-autoloader && npm run build &&
     php artisan config:clear && php artisan route:clear && php artisan migrate &&
     php artisan config:cache && php artisan route:cache && php artisan queue:restart`
    ⚠ `.env` from `webpush:vapid` ends WITHOUT a newline — never `echo X >> .env` after it
    (it glues onto VAPID_PRIVATE_KEY and silently corrupts the key).
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
  verification prompts from `auth.user.kyc_status`). Admins hard-redirect to `/admin` — but
  only from `meta.auth` routes; public routes stay browsable for an admin. The ONE
  KYC-derived routing decision is the `/loans` `beforeEnter` below, and it only picks which
  page an already-public route shows — it grants nothing.
- Design: navy `#1B2A4A` primary, green `#22C55E` accent (Tailwind `@theme` palettes
  navy-_/accent-_), Inter self-hosted, `rounded-xl/2xl` cards, `bg-gray-50` background,
  loading spinners (`animate-spin` + «Зареждане», no skeletons) and empty states; BG toasts
  for every outcome in Filament, inline feedback in most SPA views.
- `ChatbotWidget.vue` is canned Q&A (no API); its fallback contacts are stale placeholders.
- **Public landing subpages `/loans` + `/originators`** (Reni 2026-08-20 — the 3-item nav
  «стоеше голо»): guest ⇒ register-first gate and **zero data fetched** (the only request on
  the page is the boot-time `/user` probe); approved investor on `/loans` ⇒ router
  `beforeEnter` redirect to `/portfolio` (single source of truth for money — nothing
  financial is re-rendered on a public page) «докато не направим публични нещата»;
  unapproved ⇒ «чакаме одобрение» panel; admin ⇒ stays, with a «към администрацията» panel
  (no forced bounce — Reni runs Filament and her investor profile in the same browser).
  `/originators` loads nothing for ANYONE — static explanation for logged-in visitors, **no
  partner names, no claims about partner contracts** (the fabricated originator/opportunity
  cards were deleted in `7d5d0ed`; do not resurrect them, the guest teaser is shapes-only,
  never numbers, and the originator definition is quoted verbatim from Общи условия so
  marketing and contract cannot drift). Decisions live in unit-tested `utils/publicGate.js`.
  ⚠ The header/footer now render off-homepage, so their `#section` links go through
  `utils/landingNav.js` and **HomePage.vue scrolls to the hash itself** — a global router
  `scrollBehavior` was tried and removed on purpose: defining one flips
  `history.scrollRestoration` to 'manual' for the WHOLE SPA and restores position before
  async pages have their rows. Sitemap entries are hand-maintained (`public/sitemap.xml`).

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
- **Event listeners: ONE registration path — `Event::listen` in `AppServiceProvider::boot()`.**
  Laravel's automatic discovery of `app/Listeners` is switched OFF in `bootstrap/app.php`
  (`->withEvents(discover: false)`). Until 2026-08-20 both were live, so every listener was
  registered twice (discovery as `Class@handle`, the explicit call as `Class`) and each
  admin login sent two identical Telegram messages. A new listener MUST be added to
  AppServiceProvider or it never fires; `tests/Feature/EventListenerRegistrationTest.php`
  asserts the exact set, exactly once each, and fails on either mistake.
- `Cache::increment` **returns false on a missing key with the database store** (prod's
  `CACHE_STORE`), while the array store used by the test suite returns 1 — a bug class that
  passes every test and is dead in production. That is what killed the admin-login alert
  (audit 2026-07-02, fixed 2026-08-20 with `Cache::add($key, 1, $ttl) ?: increment`).
  Counters that must survive prod: `Cache::add` first, or count from the DB (see
  `SendInvestorRegisteredAlert::registrationsInWindow`).
- `envtest/` is a throwaway harness (backup-script fix proof) — candidate for deletion.
- No CI exists (no `.github/`); "CI suite" wording in phpunit.xml is aspirational.
- Docblocks referencing `DECISIONS.md` are dangling (file removed).
- Deposit codes can exist with `amount = NULL` (code issued before wire) — when counting
  "pending deposits" filter `where('amount', '>', 0)` (as TelegramDigest does; the admin UI
  uses `whereNotNull('amount')` for listing).
