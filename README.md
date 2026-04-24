<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Operations

### System cron (required)
The Laravel scheduler (`bootstrap/app.php` `withSchedule`) requires a single OS-level cron entry. Laravel dispatches all registered commands (`ledger:reconcile`, `loans:process-late`, `loans:detect-buyback-eligible`) from this one line — no per-command cron entries needed.

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled commands (in execution order):
- `03:00` `ledger:reconcile --notify` → `storage/logs/ledger-reconcile.log` (if configured)
- `03:30` `loans:process-late` → `storage/logs/loans-process-late.log`
- `03:45` `loans:detect-buyback-eligible` → `storage/logs/loans-detect-buyback-eligible.log`

The 03:30 → 03:45 dependency: buyback detection reads `loan.status` that late detection maintains. 15-min gap is a comfortable buffer over the typical < 5-min runtime; if F1 ever grows past 15 min consistently, move F2 to 04:00.

**`loans:process-late` passes** (since Phase 3 P3-F5 fix):
1. Late detection (`active → late` when ≥1 schedule past grace period).
2. Late recovery (`late → active` OR `late → repaid` per rule R1 tiebreaker).
3. **Auto-close cleanly-completing loans** (`active → repaid` when all schedules paid). See DECISIONS.md P3-02.

Metrics written to `platform_metrics` include `last_late_check_auto_repaid` — monotonic counter of auto-closed loans (monthly delta via archive query).

Verify:
```sh
crontab -l | grep schedule:run
php artisan schedule:list              # should show all 3 commands
tail -f storage/logs/loans-process-late.log
tail -f storage/logs/loans-detect-buyback-eligible.log
```

### Queue worker (required)
Notifications and password-reset emails use `ShouldQueue` with `QUEUE_CONNECTION=database`. Without a worker, jobs accumulate in the `jobs` table and never deliver.

Supervisor sample (`/etc/supervisor/conf.d/p2p-queue.conf`):
```ini
[program:p2p-queue]
command=php /path/to/app/artisan queue:work --tries=3 --timeout=90
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/app/storage/logs/queue-worker.log
```

Or systemd: standard `[Service] ExecStart=/usr/bin/php /path/to/app/artisan queue:work --tries=3` unit.

### Early repayment (F3) — admin manual trigger

F3 introduces no scheduled commands. Early repayment is executed by
the admin clicking **"Предсрочно погасяване"** on a loan's row in
Filament (**Финанси → Кредити**). The action is visible only for loans
in `active`, `late`, or `default` status. Confirmation modal shows a
fresh calculation (outstanding principal + unpaid interest through
the next-scheduled-installment boundary) plus a reminder to verify
the borrower's bank transfer before proceeding.

No new cron entries, no new log files, no new queue workers. Investor
email notifications ride the existing queue worker configured for
F1/F2.

### Fees (F4) — admin toggle, withdrawal only in v1

F4 ships fee infrastructure **disabled by default** — withdrawals
behave exactly as pre-F4 until an admin flips
`fees_withdrawal_enabled`. No new cron, no new queue workers, no new
log files.

**Activation procedure** — follow in order; skipping steps
desynchronises public messaging from actual behaviour:

1. Navigate **Финанси → Такси** in Filament as admin.
2. Read the 4-step activation banner in full.
3. Update the public copy FIRST:
    - `resources/js/components/landing/FaqSection.vue` — replace the
      "безплатно" wording with the concrete fee.
    - `resources/js/components/ChatbotWidget.vue` — same.
    - Rebuild assets (`npm run build`) + redeploy.
4. (Optional) notify existing investors via your preferred channel
   before the first chargeable withdrawal.
5. Flip `fees_withdrawal_enabled` to ON. Set the amount (default
   2.50 €; valid range 0–100, DB-enforced).
6. Save. Verify the success toast. `audit_logs` gets a row
   automatically.

**Deactivation** is the reverse: flip the toggle off, then amend the
public copy back to "безплатно" (or whatever the new policy calls for).

**Reconciliation** — every charged fee creates one `type='fee'`
transaction row with reference `withdrawal_request:{id}:fee`. Reconcile
against the business bank account:

```sql
SELECT
    DATE(created_at)    AS day,
    COUNT(*)            AS withdrawals,
    SUM(amount)         AS fee_total
FROM transactions
WHERE type = 'fee'
  AND created_at >= '2026-05-01'
  AND created_at <  '2026-06-01'
GROUP BY DATE(created_at)
ORDER BY day;
```

FeesPage (Filament) shows the same totals in two stat cards
(all-time + current month) + the latest 10 fee transactions for a
quick spot-check without dropping into SQL.

**Public API** — `GET /api/fees/config` (no auth, throttle 60/min):

```
→ 200 {"withdrawal":{"enabled":false,"amount":"2.50"}}
```

Consumed by the investor SPA's `WithdrawalPage.vue` to render the
live breakdown. Public because the fee schedule is advertised on the
landing FAQ + chatbot.

**Known limitations (see AUDIT_REPORT_PHASE_F4.md for detail):**
- Withdrawal category only in v1; origination / service / late /
  early-repayment / inactivity are placeholder-ready but not
  implemented.
- No platform-wallet model — fees live in admin's bank account
  off-platform. Platform ledger records the debit; bank statement
  is the revenue source of truth.
- Vue breakdown rendering not covered by automated browser tests;
  manual QA on first activation is required (see pre-deploy
  checklist in the F4 audit report).

### APR (F5) — ГПР display & regulatory disclosure

F5 surfaces the Annual Percentage Rate — "ГПР" (Годишен Процент на
Разходите) — for each loan. EU Consumer Credit Directive
(2008/48/EC) alignment for borrower-cost disclosure, plus
investor-facing transparency about the originator's pricing.

**Formula (v1):** nominal pass-through of `loans.interest_rate_annual`
formatted to 2 decimals. For a no-fee annuity loan the nominal
borrower rate IS the EU CCD APR by definition — pass-through is
exact, not approximation. See `DECISIONS.md` F5-01 for the full
proof and the handoff-formula correction.

**Upgrade path:** when any borrower-side fee activates in F4's
`FeeService::CATEGORIES` (origination / service / late / inactivity
— all placeholder-ready today), swap `APRCalculatorService::calculate()`
for a Newton-Raphson IRR solver in bcmath. Caller contract
preserved — Vue, Filament, API consumers untouched.

**Where it displays:**
- Admin Filament **Кредити**: new sortable "ГПР (APR)" column;
  edit page has a read-only "Ставки" section with
  Доходност / ГПР / Марж (admin-only spread visibility).
- Investor `/marketplace`: new "ГПР" column (desktop) + sub-line
  on the mobile card.
- Investor `/invest/{id}`: new stat cell in the 5-cell grid +
  helper paragraph distinguishing "Доходност" (your yield) from
  "ГПР" (borrower cost).
- API `/api/loans` + `/api/loans/{id}`: new `apr` field (2-decimal
  string or null).
- **NOT shown** on landing, dashboard, or portfolio pages — ГПР
  is a loan-detail concept; summary views stay uncluttered.

**Null-safe fallback:** when `interest_rate_annual` is null or zero
(legacy rows before the F5 form `minValue(0.01)` guard),
APRCalculatorService returns null, API emits JSON null, and UI
shows "—" instead of a misleading "0.00%". No production row can
be null at the DB level (column is `NOT NULL decimal(5,2)`); zero
is the only practical fallback trigger.

**Deploy note:** no migration, no composer update. Run `npm run
build` to refresh `public/build/` — Vue changes in MarketplacePage
and InvestmentDetailPage won't show the new column/cell otherwise.

### Health monitoring
Public endpoint covers BOTH `loans:process-late` (F1) and `loans:detect-buyback-eligible` (F2) via a single URL:

```
GET /api/health/scheduler
→ 200 healthy / warning   (both schedulers last run ≤ 48 h ago)
→ 503 critical            (either scheduler > 48 h stale, or never run)
```

Response shape:
- Top-level flat fields describe the late scheduler (F1 backwards compat).
- Nested `buyback` block describes the F2 scheduler (same field names).
- Top-level `status` = WORST of the two, with **disabled schedulers
  excluded** (ops rule — a deliberately-toggled-off scheduler via its
  `*_check_enabled` platform setting does not trigger a critical alert).
  If both are disabled, overall status is `healthy`.

No auth, throttled 60/min. Configure UptimeRobot / Healthchecks.io / Pingdom against this URL.

### Timezone
Set `APP_TIMEZONE=Europe/Sofia` in `.env`. The late-detection command uses `config('app.timezone')` for "today" boundaries; default `UTC` will produce off-by-one date arithmetic vs ops expectations.

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
