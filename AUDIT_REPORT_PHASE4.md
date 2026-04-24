# Phase 4 — Infrastructure Audit

**Branch:** `claude/vigilant-albattani-12cdd9` (from `main` at `2dc533f`)
**Base commit:** `2dc533f` (Phase 3 merged + Phase 4 handoff doc — 630 tests passed, 1300 + 1823 assertions)
**Session start:** 2026-04-24
**Scope:** Operational readiness of vamaasset.bg production server (Hetzner CCX23). Backup strategy, process reliability, security hardening, monitoring, deploy procedure. NOT code review (prior phases closed).

**Status:** 🟡 Step 0 complete — 14 findings surfaced, 2 closed during verification (P4-H1 no-fix, P4-R1 retracted), fix plan proposed for the remaining 12, awaiting user/mentor approval on scope before execution.

---

## Step table

| Step | Description | Status |
|---|---|---|
| 0 | Read-only SSH discovery — inventory production state, verify docx vs reality, identify gaps | ✅ complete |
| 1 | Wave 1 — Launch blockers (P4-Q1 admin default + P4-H1 scheduler failure + P4-S1 cron visibility + P4-SSH1/3 SSH hardening) | ⏸ pending approval |
| 2 | Wave 2 — Backups (P4-B1 five sub-issues: mysqldump, off-vendor, retention, restore test, Bulgarian runbook) | ⏸ pending |
| 3 | Wave 3 — Monitoring + config hardening (UptimeRobot + P4-D1/D2/W1/L1/S2/SSH2) | ⏸ pending |
| 4 | Wave 4 — Deploy enhancement (zero-downtime pattern + smoke tests + runbook) | ⏸ pending |
| 5 | Finalize — AUDIT_REPORT updates per Wave + DECISIONS.md P4-NN entries + CLAUDE.md Operations section | ⏸ pending |

---

## §1 Methodology

### 1.1 Discovery mode — read-only, two-party collaborative

User (Yordan) has SSH access to production. Claude has no SSH. Workflow: Claude produces self-contained command batches, user pastes them into an SSH session, output pasted back to chat. No writes to production in Step 0.

Eight batches covered:

1. **System baseline** — kernel, OS, uptime, disk, RAM, CPU, timezone.
2. **Deploy state** — git HEAD, PHP/Composer/Node versions, `.env` production flags, bootstrap cache freshness.
3. **Schedulers** — crontabs (yordan/root/www-data), systemd timers, `php artisan schedule:list`, `journalctl` for `schedule:run` firing evidence.
4. **Queue/Supervisor** — `supervisorctl status`, config file, `jobs`/`failed_jobs` counts.
   - **4B** (follow-up) — triaged a fresh failed email to identify SMTP vs recipient-address issue.
5. **Backups** — `/var/backups`, filesystem `find` for `.sql` files, cron jobs mentioning backup tools, installed tools (restic/borg/rclone/rsync/duplicity/mysqldump), off-site credential files.
6. **Security hardening** — sshd config (`PasswordAuthentication`, `PermitRootLogin`, …), UFW status, fail2ban jails + banned IP list, recent `/var/log/auth.log` failures, listening ports (non-loopback).
7. **Web / DB / Logs / SSL** — nginx config (server_name, SSL cert path, headers, fastcgi), MySQL version/status, Laravel logs, logrotate presence, certbot cert expiry + renewal timer, `~/.ssh/` state for yordan.
8. **Laravel health + final verify** — `/api/health/scheduler` endpoint, full nginx headers recursively, `migrate:status`, `platform_settings` dump, `platform_metrics` dump, admin users listing (first attempt had SQL error — see P4-Q1 evidence pending).

### 1.2 Source-of-truth crosswalk

| Source | Role |
|---|---|
| `vamaasset-server-setup.docx` (Yordan's own, April 2026) | Documented intended state |
| Live SSH output | Actual state |
| `CLAUDE.md` + handoff doc | Code/architecture expectations (F1–F5 + P3) |

Findings are rooted in divergence between these sources, or in gaps none of them cover.

---

## §2 Findings — 14 items

**Distribution:** 2 CRITICAL, 4 HIGH, 5 MEDIUM, 3 LOW.

### §2.1 CRITICAL — Launch blockers

#### P4-Q1 (CRITICAL — deferred to pre-launch data wipe) — Default admin account `admin@p2p.com/password` is live and in use

**Deferral rationale (agreed 2026-04-24):** the platform is pre-launch with only test data. All test accounts (including `admin@p2p.com`) will be truncated during the launch data-wipe procedure. Fixing now means fixing twice. Instead, the default-admin elimination moves into the **pre-launch launch-day checklist** (not Wave 1) with these acceptance criteria:

1. `admin@p2p.com` user row absent from `users` table after wipe.
2. New real admin user created with (a) client's/Yordan's real email + (b) strong unique password stored in password manager + (c) Filament login verified.
3. AdminLoginAlertMail tested with new real admin → email delivers to a real inbox (no 550 bounce).
4. Hetzner Cloud Backup snapshots taken BEFORE wipe to be rotated/discarded; first snapshot post-wipe is the "clean" reference.
5. Run `php artisan tinker --execute='\App\Models\User::where("role","admin")->count()'` — expect 1 row (or as many legitimate admins as the client chooses).

**Severity stays CRITICAL** — this is a launch blocker, just not a Wave 1 (today) blocker. Added to `docs/runbooks/pre-launch-checklist.md` (to be written as part of Wave 4).

### §2.1-old P4-Q1 original finding (kept for audit trail)

**Evidence:** worker.log shows `App\Mail\AdminLoginAlertMail` fired 2026-04-24 13:55:09 and was bounced by Superhosting SMTP with `550 admin@p2p.com domain may not exist`. The mail was addressed **to the admin account itself** — meaning a real admin login happened at 13:55 today as `admin@p2p.com`, and the login-alert notification was queued to notify that same account.

**Root cause:** seeded-default account from early setup was never cleaned up. `vamaasset-server-setup.docx` §13–14 explicitly flagged "admin@p2p.com / password (СМЕНИ ПРЕДИ LAUNCH!)" — action was not taken.

**Impact:**
- Anyone with basic platform knowledge (seed default) can log into Filament admin panel and access all investor data + initiate money movements.
- AdminLoginAlertMail is the compensating control (Phase 1 security audit artefact) — but it only fires AFTER a login, not BEFORE, and it bounces to an invalid address anyway.
- All Hetzner Cloud Backup snapshots (7-day retention) currently contain the compromised credential. Even after rotation, old snapshots carry the live seed until they age out.

**Severity:** CRITICAL. Platform cannot launch with real money while this account is active.

**Fix (Wave 1):**
1. Create real admin user (`CreateAdminUser` command or Filament first-login flow) with strong unique password and Yordan's real email.
2. Verify login + admin role + 2FA compensating controls (Phase 1 IP allow-list, AdminLoginAlert).
3. Delete (hard-delete, not soft) `admin@p2p.com`. Update `users` + purge related sessions.
4. Clear `failed_jobs` entry `5bf44dd6-a791-4e1b-9a67-f09bc053187b` (garbage artefact).
5. Rotate any Hetzner Cloud Backup snapshots younger than the fix by creating a manual snapshot post-fix; let the old ones age out naturally over 7 days, but document the risk window.

**Effort:** 30 minutes.

#### P4-H1 (CLOSED — no fix required) — F1 + F2 schedulers had never cycled yet post-deploy

**Resolution (2026-04-24 14:29 UTC):** verified via `php artisan schedule:test --name='loans:process-late'` and same for `loans:detect-buyback-eligible`. Both commands executed successfully in 227ms / 209ms respectively, wrote 9 + 5 rows to `platform_metrics`, created `storage/logs/loans-process-late.log` + `loans-detect-buyback-eligible.log`, and `/api/health/scheduler` now returns `"status":"healthy"` for both sub-systems.

**Root cause:** F1/F2 scheduler entries in `bootstrap/app.php` were deployed to production at 13:53 UTC on 2026-04-24 (the `config:cache` regen timestamp). The next cron windows at 03:30 / 03:45 UTC had simply not yet been reached. There was no spawn / permission / code defect — just deploy timing. Tomorrow's 03:30 UTC cron window will trigger the full schedule normally.

**Leaves behind:** P4-S1 (cron output → `/dev/null`) is STILL a real finding — once cron fires F1/F2 tomorrow, if they ever fail we have no visibility. P4-S1 remains MEDIUM and in Wave 1.

### §2.1-old P4-H1 original investigation notes (kept for audit trail)

**Evidence:** `GET /api/health/scheduler` from the server itself returns **HTTP 503**:

```json
{
  "status":"critical",
  "last_run_at":null,
  "minutes_since_last_run":null,
  "late_check_enabled":true,
  "last_run_stats":{"status":null, "loans_scanned":null, ...},
  "buyback":{"status":"critical","last_run_at":null,"enabled":true,...}
}
```

`platform_metrics` table is **empty** (zero rows). The server has **16 days uptime**; Laravel `schedule:list` shows all three commands registered (ledger:reconcile 03:00, loans:process-late 03:30, loans:detect-buyback-eligible 03:45); cron ticker fires `schedule:run` every minute (confirmed in `journalctl`). Yet no `last_*_run_at` row has ever been written. This means either:

- The scheduled commands are not actually being executed by `schedule:run` (some condition prevents them), OR
- They execute but fail silently before writing their metric, OR
- They execute and no-op but the metric-write codepath requires non-zero work (requires source verification).

**Amplifier:** P4-S1 (cron output redirected to `/dev/null`) means any exception thrown during execution is invisible.

**Impact:**
- F1 late-detection doesn't fire → loans entering the late period are not marked, investors are not notified, `LoanWentLateNotification` is never dispatched.
- F2 buyback eligibility doesn't fire → originator buyback obligations aren't tracked, Buyback Queue admin page always shows empty, the admin digest is never sent.
- Ledger reconcile doesn't fire → nightly integrity check is silent; a platform corruption could go undetected for weeks.
- F1-F5 correctness work invested during this audit series — `LateDetectionService`, `LoanStatusUpdaterService`, `BuybackEligibilityService`, `RepaymentService`, the full loan_events pipeline — is all effectively dead code as far as production is concerned.

**Severity:** CRITICAL. The platform's entire lifecycle-automation layer is non-functional.

**Fix (Wave 1):**
1. **P4-S1 first** — restore cron output visibility so subsequent diagnostics produce readable logs.
2. Manual diagnosis: `sudo -u root php artisan loans:process-late --dry-run --detail` — reproduces the cron's exact context and reveals crash/no-op source.
3. Likely culprits:
   - Stuck `withoutOverlapping` cache lock (Redis/file — clear with `php artisan cache:clear`).
   - Permission issue — cron runs as root but storage is `yordan:www-data`. `schedule:run` as root works but app-level writes might fail if setgid isn't preserved.
   - App-level silent exception before metric-write. Investigate `LoanStatusUpdaterService::recordMetrics` codepath.
4. Fix root cause + write `AUDIT_REPORT_PHASE4.md` §2.1 P4-H1 diagnosis update + add a regression test pinning "metric is written even on zero-work runs".
5. Verify fix by letting tomorrow's 03:00/03:30/03:45 cron fire + re-query `/api/health/scheduler` → expect `"status":"healthy"`.

**Effort:** 1–4 hours depending on diagnosis depth.

### §2.2 HIGH — Pre-launch must-fix

#### P4-B1 (ACCEPTED RESIDUAL RISK — decision 2026-04-24) — Backup strategy is Hetzner Cloud Backups only

**Decision:** stay with **Hetzner Cloud Backups** (7-day daily full-VM snapshots) as the sole backup mechanism for now. No additional logical dump layer, no off-vendor copy, no restore drill, no runbook.

**User reasoning:** pre-launch with test data only, no real investor money at stake. The 7-day VM snapshots cover the "server died / rm -rf went wrong" scenarios. The remaining risks (mid-transaction snapshot corruption, same-vendor compromise, > 7-day rollback need, untested restore, no admin runbook) are accepted as residual.

**Re-evaluation triggers — MUST revisit before:**

1. **Data wipe → real-money launch** — see P4-Q1 pre-launch checklist. At that moment P4-B1 should be promoted back from "accepted risk" to "open HIGH"; a proper layered backup strategy (mysqldump + off-vendor + restore drill + BG runbook) is not optional for production handling real investor funds.
2. **First real investor deposit** — even before full launch, the moment any real EUR enters a wallet, Hetzner-only is not enough.
3. **First MySQL schema migration in production** — ideally a pre-migration manual mysqldump baseline exists.
4. **Regulatory / mentor feedback** — fintech legal review is likely to flag this; bring forward if called out.

**Not-implemented work** (kept here so the next session knows what to build when triggered):

- `app/Console/Commands/Ops/EmailBackup.php` — artisan command for emailing backup via Laravel SMTP
- `scripts/ops/mysql-backup.sh` — transaction-consistent `mysqldump --single-transaction` + gzip + rotation
- `scripts/ops/mysql-restore.sh` — confirmation-gated restore + post-restore checklist
- Bulgarian runbook `docs/runbooks/restore-bg.md`
- Monthly scratch-DB restore drill

Rough effort estimate for the full stack when needed: 4–6 hours implementation + ongoing operational cost (one monthly drill).

### §2.2-old P4-B1 original finding (kept for audit trail — see "Five sub-issues" table below)

**Evidence:**
- Hetzner Cloud Backups enabled (7 daily full-VM snapshots, confirmed via Hetzner console screenshot).
- `/var/backups/` contains only Ubuntu package-management artefacts (alternatives, dpkg status) — no application data.
- `find / -name "*.sql*" -mtime -30` returns zero results — no mysqldumps on the VM.
- No backup-tool cron jobs — cron search for `backup|mysqldump|restic|borg|rsync|hetzner` returns only the Ubuntu default commented-out example.
- `restic`, `borg`, `rclone`, `duplicity` not installed. `rsync` + `mysqldump` available.
- No off-site credential files present anywhere obvious.

**Five sub-issues:**

| Sub | Issue | Risk |
|---|---|---|
| a | Hetzner disk snapshots are crash-consistent, not logical DB-consistent | Mid-transaction snapshot → InnoDB redo replay → corruption window on restore |
| b | All 7 snapshots in same Hetzner account | Vendor outage / billing / account-compromise → simultaneous loss of VM and all backups |
| c | 7-day retention | Corruption bug discovered on day 8 has no clean recovery point. Financial-data industry standard: 30-90 day daily + monthly archives for 6–12 months |
| d | Restore has never been tested | "A backup never restored is not a backup" — untested recovery = unverified recovery |
| e | No restore runbook in Bulgarian for non-technical admin (клиентката) | If Yordan is unavailable during an incident, admin cannot execute recovery |

**Severity:** HIGH (not CRITICAL because Hetzner snapshots provide SOME recovery path).

**Fix (Wave 2):**
1. **P4-B1a:** Daily `mysqldump --single-transaction --routines --triggers --events --hex-blob` at 02:30 UTC (before ledger:reconcile at 03:00 → pre-F1/F2 baseline). gzip output. Local storage `/var/backups/mysql/`.
2. **P4-B1b:** Off-vendor target — **Hetzner Storage Box** (€1.19/mo 1 TB, SSH/SFTP, separate billing account from Hetzner Cloud) OR **Wasabi S3-compatible** (€5.25/mo 1 TB, truly different vendor). Client/mentor decides — see §4.
3. **P4-B1c:** Retention — 30 daily + 12 monthly (first-of-month) archives. Rotation via `find -mtime +30 -delete` in local; Storage Box lifecycle handles remote.
4. **P4-B1d:** Monthly restore drill — scratch MySQL container, restore yesterday's dump, run `php artisan tinker` smoke check (count investors + loans + total ledger balance matches live). Automate via a third cron.
5. **P4-B1e:** `docs/runbooks/restore-bg.md` — Bulgarian, step-by-step, photos of each command output, escalation contact list. Stored in repo + printed copy in admin's physical location.

**Effort:** 4–6 hours total (split across 4 sub-commits).

#### P4-D1 (HIGH) — `SESSION_ENCRYPT=false` contradicts documented spec

**Evidence:** `.env` on server has `SESSION_ENCRYPT=false`. `vamaasset-server-setup.docx` §7 explicitly lists `SESSION_ENCRYPT=true`.

**Impact:** Laravel sessions are DB-driven (`SESSION_DRIVER=database`). Session payload contains `_token` (CSRF), flash messages, and depending on implementation possibly `user_id`, `intended_url`, KYC state. Without encryption these are stored as serialized PHP plaintext in the `sessions` table. If a DB dump leaks (including via the Hetzner Cloud Backup snapshots noted in P4-B1), session contents are readable without keys. Sessions ARE signed (tamper-proof) so the risk is confidentiality, not integrity.

**Severity:** HIGH. Defense-in-depth gap explicitly called out in the setup doc and not followed.

**Fix (Wave 3):**
1. `SESSION_ENCRYPT=true` in `.env`.
2. `php artisan config:cache`.
3. `sudo supervisorctl restart p2p-worker:*`.
4. `sudo systemctl reload php8.3-fpm`.
5. Note: all existing session rows become undecryptable → active users force-logged-out on next request. Acceptable one-time pain.

**Effort:** 5 minutes.

#### P4-SSH1 (CLOSED — fix applied 2026-04-24 14:40 UTC) — `PasswordAuthentication=yes` + active brute-force

**Resolution commit:** (pending — will land as `fix(ops): disable sshd password authentication (P4-SSH1)`)

**Gotcha encountered:** initial fix placed override at `60-p2p-lending.conf` (lexicographically AFTER `50-cloud-init.conf`). SSH config uses **first-match-wins** semantics (unlike most Linux configs), so `50-cloud-init.conf`'s `PasswordAuthentication yes` beat our `no`. Fix re-deployed as `10-p2p-lending.conf` — loads before `50-cloud-init.conf`, overrides correctly.

**Verification:**
- `sudo sshd -T | grep passwordauth` → `passwordauthentication no` ✓
- Positive test from fresh session: `ssh -i ~/.ssh/id_ed25519_p2p yordan@178.104.78.0 "echo KEY_AUTH_OK"` → success ✓
- Negative test: `ssh -o PubkeyAuthentication=no -o PreferredAuthentications=password yordan@178.104.78.0` → `Permission denied (publickey).` ✓

Active brute-force attacks visible in `/var/log/auth.log` (pre-fix) now hit a closed door — password no longer an accepted auth method. Lesson captured: SSH `sshd_config.d/*.conf` files load in lexicographic order AND use first-match-wins; file numbering must be BEFORE any existing overrides.

### §2.2-old P4-SSH1 original finding (kept for audit trail)

**Evidence:** `/etc/ssh/sshd_config.d/50-cloud-init.conf` sets `PasswordAuthentication yes` (Hetzner cloud-init default). Simultaneously, live `fail2ban` jail shows **877 total failed / 168 total banned / 6 currently banned** — active brute-force in progress during this audit. `auth.log` shows `root` being hammered from multiple IPs:

```
14:02:29 Failed password for root from 195.178.110.15 (+ 4 repeats)
14:06:08 Failed password for root from 2.57.122.190    (+ 4 repeats)
```

`PermitRootLogin no` blunts current attacks, but if an attacker guesses that `yordan` is the actual username (shown in any unsuccessful connection MOTD), they switch targets. Distributed brute-force (1 try per 1000 IPs) can defeat fail2ban's per-IP threshold.

**Severity:** HIGH. For a financial platform on public Internet, password-auth SSH is a known attack surface.

**Blocked by:** P4-SSH3.

**Fix (Wave 1, after P4-SSH3):**
1. Create `/etc/ssh/sshd_config.d/60-p2p-lending.conf`:
   ```
   PasswordAuthentication no
   ChallengeResponseAuthentication no
   AuthenticationMethods publickey
   ```
   (filename `60-*` loads after `50-cloud-init.conf` → wins precedence).
2. Validate: `sudo sshd -t`.
3. Keep current SSH session open as safety net. From a separate terminal, test new login with key.
4. `sudo systemctl reload sshd`.
5. Verify parallel session still works; then close the safety-net session.

**Effort:** 5 minutes (after SSH3).

#### P4-SSH3 (CLOSED — fix applied 2026-04-24 14:35 UTC) — No `~/.ssh/authorized_keys` for yordan (was blocking P4-SSH1)

**Resolution:** ed25519 keypair generated on Yordan's Windows dev machine (`ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_p2p`, passphrase set). Public key appended to `/home/yordan/.ssh/authorized_keys` on production; `~/.ssh/` permissioned `700`, `authorized_keys` permissioned `600`. Comment tag: `yordan-p2p-prod-20260424`.

**Verification:**
- `ls -la ~/.ssh/` shows correct `drwx------` + `-rw-------` ✓
- Test login from fresh session (passphrase prompt only, no password prompt) → landed on server shell ✓
- Unblocked P4-SSH1.

### §2.2-old P4-SSH3 original finding (kept for audit trail)

**Evidence:** `ls -la /home/yordan/.ssh/` returns `No such file or directory`. Yordan's current SSH sessions are password-only. Disabling password-auth without first setting up key-auth would lock the developer out.

**Severity:** HIGH (not CRITICAL because password-auth currently works — but it's a hard blocker for P4-SSH1, which is itself HIGH).

**Fix (Wave 1, prerequisite for P4-SSH1):**
1. On Yordan's dev machine (Windows):
   ```sh
   ssh-keygen -t ed25519 -C "yordan@laptop" -f ~/.ssh/id_ed25519_p2p
   ```
2. Upload public key:
   ```sh
   ssh-copy-id -i ~/.ssh/id_ed25519_p2p.pub yordan@178.104.78.0
   ```
   (prompts for current password once).
3. Test key login:
   ```sh
   ssh -i ~/.ssh/id_ed25519_p2p yordan@178.104.78.0
   ```
4. Persist in `~/.ssh/config` for Yordan's convenience:
   ```
   Host p2p-prod
     Hostname 178.104.78.0
     User yordan
     IdentityFile ~/.ssh/id_ed25519_p2p
     IdentitiesOnly yes
   ```

**Effort:** 10 minutes.

### §2.3 MEDIUM

#### P4-D2 (MEDIUM) — `APP_TIMEZONE` missing from `.env`, defaults to UTC

**Evidence:** `.env` grep for `APP_TIMEZONE` returns nothing. System timezone is `Etc/UTC`. `config/app.php` default is also UTC.

**CLAUDE.md** (F1 section) explicitly recommends `APP_TIMEZONE=Europe/Sofia` for production because the F1 grace-period computation uses `Carbon::now(config('app.timezone'))->startOfDay()`. In UTC, the "today minus 10 days" boundary crosses at 00:00 UTC = 02:00 or 03:00 Sofia (DST-dependent), which means schedules are marked late 2–3 hours shifted from intended local-day alignment.

**Impact:** Not a financial error (the boundary math is consistent within a single timezone). Just a mis-alignment between documented intent and actual operational behaviour. Low-business-consequence but undermines the "it works as documented" posture.

**Fix (Wave 3):**
1. Add `APP_TIMEZONE=Europe/Sofia` to `.env`.
2. `php artisan config:cache`.
3. Run `php artisan loans:process-late --dry-run --detail` to see the boundary shift impact on existing data.
4. Supervisor + PHP-FPM reload.

**Effort:** 5 minutes.

#### P4-D3 (MEDIUM) — Redis installed but not used

**Evidence:** `vamaasset-server-setup.docx` §3.1 claims Redis for "cache, sessions, queue driver". Actual `.env`:
- `SESSION_DRIVER=database`
- `QUEUE_CONNECTION=database`
- `CACHE_DRIVER` unset → default `file`

**Impact:** All three subsystems hit MySQL or disk instead of Redis. At pre-launch load this is invisible. Post-launch, F1 notification bursts (LoanWentLate for N investors at 03:30) compete with ledger writes for DB connection pool + lock contention.

**Severity:** MEDIUM. Not a correctness issue; a performance one.

**Fix (Wave 3 or defer to v1.1):**
1. `CACHE_DRIVER=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis` in `.env`.
2. Confirm `REDIS_HOST=127.0.0.1` and password if set.
3. Migrate existing sessions + jobs: users logged out (acceptable); pending jobs need explicit re-dispatch if any — safer to deploy at low-traffic window.
4. Add basic Redis monitoring (memory usage + connection count) — plug into same UptimeRobot or stats endpoint as health check.

**Effort:** 30 minutes if adopted; 0 if deferred.

#### P4-S1 (CLOSED — fix applied 2026-04-24 14:42 UTC) — Cron output redirected to `/dev/null`

**Resolution:** root crontab modified via `sed` pipeline:
- Before: `* * * * * cd /var/www/p2p-lending && php artisan schedule:run >> /dev/null 2>&1`
- After: `* * * * * cd /var/www/p2p-lending && php artisan schedule:run 2>&1 | /usr/bin/logger -t p2p-laravel-scheduler`

Output now lands in systemd journal, queryable via `sudo journalctl -t p2p-laravel-scheduler`. Verified next-minute tick at 14:42 UTC logged: `INFO  No scheduled commands are ready to run.` (expected — not 03:XX window).

**Backup:** `/root/crontab-backup-20260424-144200.txt` preserved for rollback.

### §2.3-old P4-S1 original finding (kept for audit trail)

**Evidence:** `sudo crontab -l` shows:
```
* * * * * cd /var/www/p2p-lending && php artisan schedule:run >> /dev/null 2>&1
```

**Impact:** Any exception, PHP parse error, or unexpected stdout from `schedule:run` is unobservable. This amplifies P4-H1 (cron fires but we cannot see why the scheduled commands aren't landing metrics). `laravel.log` captures explicit `Log::*()` calls but not framework-level process crashes.

**Severity:** MEDIUM. On its own it's visibility hygiene; combined with P4-H1 it's an active-incident amplifier.

**Fix (Wave 1, before P4-H1 diagnosis):**
Change cron entry to:
```
* * * * * cd /var/www/p2p-lending && php artisan schedule:run 2>&1 | /usr/bin/logger -t p2p-laravel-scheduler
```
Benefit: output lands in systemd journal, queryable via `journalctl -t p2p-laravel-scheduler`, subject to journal retention/rotation (no disk bloat).

Alternative: add `->emailOutputOnFailure('yordanyordanov0104@gmail.com')` to each scheduled task in `bootstrap/app.php`. More precise (email only on exit-code != 0) but more code churn.

**Effort:** 5 minutes.

#### P4-W1 (MEDIUM) — nginx missing 3 security headers from docx spec

**Evidence:** Full recursive grep of `/etc/nginx/` for `add_header` shows only 5 headers present:
- X-XSS-Protection
- Referrer-Policy
- Permissions-Policy
- X-Robots-Tag
- Content-Security-Policy

**Missing** (all listed in `vamaasset-server-setup.docx` §5):
- `Strict-Transport-Security` (HSTS)
- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`

**Impact:** Clickjacking attack surface (no X-Frame-Options), MIME-sniffing attacks (no X-Content-Type-Options), SSL-stripping attack surface (no HSTS pre-load). Each is individually "best-practice", collectively they drop SSL Labs rating from A+ to A.

**Severity:** MEDIUM. Pre-launch these are theoretical risks — no real investors yet — but post-launch they widen the attack surface.

**Fix (Wave 3):**
Add to `/etc/nginx/sites-available/vamaasset.bg` in the `server { listen 443 ... }` block:
```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
```
Then `sudo nginx -t && sudo systemctl reload nginx`.

**Effort:** 15 minutes (including retest).

### §2.4 LOW

#### P4-S2 (LOW) — No automatic reboot after kernel security patches

**Evidence:** `apt-daily-upgrade.timer` active (systemd) → unattended-upgrades running → but 16-day uptime + recent kernel release (6.8.0-107 from March 2026) → no recent reboot → patches may be queued but not applied.

**Impact:** Kernel patches stall. On a financial platform accepting security posture must be current. Manual reboot required monthly is error-prone.

**Fix (Wave 3):** Enable `Unattended-Upgrade::Automatic-Reboot "true";` + `Automatic-Reboot-Time "04:15";` in `/etc/apt/apt.conf.d/50unattended-upgrades`. 04:15 UTC = 06:15/07:15 Sofia — after all F-phase crons (03:00/03:30/03:45), during lowest-traffic window. Reboot takes ~30 sec; sessions re-establish on next request.

**Effort:** 5 minutes.

#### P4-SSH2 (LOW) — fail2ban only covers sshd, not Filament admin

**Evidence:** `fail2ban-client status` shows one jail: `sshd`. No `nginx-auth`, `nginx-filament`, or similar jail for brute-force against `/admin/login`.

**Impact:** While P4-Q1 (admin default password) is active, brute-force on `/admin/login` is the direct attack path. Laravel's default `throttle:5,1` rate-limits per IP per minute — tighter than fail2ban's default 6 in 10-minute window, but doesn't ban at the network layer. Distributed brute-force bypasses both.

**Severity:** LOW. Primary mitigation is P4-Q1 (fix the creds, not the jail).

**Fix (Wave 3):** Add nginx-filament jail in `/etc/fail2ban/jail.d/nginx-filament.conf` matching 401/403 responses on `/admin/*` paths. Filter in `/etc/fail2ban/filter.d/nginx-filament.conf`.

**Effort:** 15 minutes.

#### P4-L1 (LOW) — No logrotate for `laravel.log`

**Evidence:** `/etc/logrotate.d/` lists nginx, mysql-server, fail2ban, redis-server, php8.3-fpm etc. — no `laravel` or `p2p-lending` entry. Current `laravel.log` size: 37K (pre-launch traffic). Worker log: 481 bytes.

**Impact:** At launch, `laravel.log` will accumulate all F1 notification queue logs, KYC events, ledger reconcile output, any ERRORs from the stack. Unbounded growth → disk pressure → eventual full disk → production outage.

**Severity:** LOW (current size trivial) but trending to HIGH at launch.

**Fix (Wave 3):** Create `/etc/logrotate.d/laravel-p2p`:
```
/var/www/p2p-lending/storage/logs/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
    su yordan www-data
}
```
`copytruncate` avoids restarting PHP-FPM. 30-day retention matches backup retention policy.

**Effort:** 5 minutes.

#### P4-W2 (LOW, defer to v1.1) — CSP allows `unsafe-inline` + `unsafe-eval`

**Evidence:** Current `Content-Security-Policy` header: `script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline' ...`.

**Impact:** `unsafe-inline` + `unsafe-eval` mean inline `<script>` tags and `eval()`-like constructs are permitted — this is the default Vue + Filament compatibility posture, but it widens XSS mitigation gaps.

**Severity:** LOW. A strict CSP (nonce-based or hash-based) is a v1.1 investment requiring Filament compatibility testing — not a prerequisite for launch.

**Fix:** v1.1 — research Filament CSP nonce support, measure inline-script count in Vue build, pilot migration.

**Effort:** 1–2 days (v1.1).

---

## §3 Proposed fix plan — 4 Waves

### Wave 1 — Launch blockers (Day 1)

| # | Item | Effort | Depends on |
|---|---|---|---|
| 1 | P4-S1 (cron visibility) | 5 min | — |
| 2 | P4-H1 diagnosis (manual `--dry-run --detail`) | 15 min | #1 |
| 3 | P4-H1 fix (root cause) | 30 min – 4 h | #2 |
| 4 | P4-Q1 (admin default cleanup) | 30 min | — |
| 5 | P4-SSH3 (SSH key setup) | 10 min | — |
| 6 | P4-SSH1 (disable PasswordAuthentication) | 5 min | #5 |

**Wave 1 total:** 1.5–5 hours depending on P4-H1 complexity.

### Wave 2 — Backups (Day 2)

| # | Item | Effort | Depends on |
|---|---|---|---|
| 7 | P4-B1a (daily mysqldump) | 1 h | Wave 1 |
| 8 | P4-B1b (off-vendor sync — provider decided in §4) | 2 h | #7 + §4 decision |
| 9 | P4-B1c (30/12 retention rotation) | 30 min | #8 |
| 10 | P4-B1d (monthly restore drill script) | 1 h | #9 |
| 11 | P4-B1e (Bulgarian runbook `docs/runbooks/restore-bg.md`) | 1 h | #10 (drill proves what works) |

**Wave 2 total:** 5.5 hours.

### Wave 3 — Monitoring + config hardening (Day 3)

| # | Item | Effort |
|---|---|---|
| 12 | UptimeRobot (external party — see §4) | 15 min |
| 13 | P4-D1 (SESSION_ENCRYPT) | 5 min |
| 14 | P4-D2 (APP_TIMEZONE) | 5 min |
| 15 | P4-W1 (3 missing nginx headers) | 15 min |
| 16 | P4-L1 (logrotate) | 5 min |
| 17 | P4-S2 (auto-reboot) | 5 min |
| 18 | P4-SSH2 (nginx fail2ban jail) | 15 min |
| 19 | P4-D3 (Redis adoption — OR defer) | 30 min |

**Wave 3 total:** 1.5 hours (or 1 h if D3 deferred).

### Wave 4 — Deploy enhancement (Day 4)

Per HANDOFF §4.6:

| # | Item | Effort |
|---|---|---|
| 20 | Zero-downtime deploy pattern (releases/ + symlink swap OR Laravel Deployer) | 2–3 h |
| 21 | Post-deploy smoke-test script | 1 h |
| 22 | `docs/runbooks/deploy.md` | 1 h |

**Wave 4 total:** 4–5 hours.

---

## §4 Mentor / client decisions required

Decisions outside Claude's scope — Yordan + mentor + client must agree:

1. **Backup off-site provider** (blocks Wave 2 #8):
   - Hetzner Storage Box — €1.19/mo, 1 TB, same vendor (some same-vendor risk remains)
   - Wasabi S3-compatible — €5.25/mo, 1 TB, fully different vendor
   - Different Hetzner region — €3.50/mo, 1 TB, same company but different datacentre

   Recommendation: **Hetzner Storage Box** for v1 — cheapest, zero extra vendor relationship, materially reduces single-VM-disk risk. Revisit at v1.1 when real-money volume justifies Wasabi's true off-vendor guarantee.

2. **Backup retention policy** (Wave 2 #9):
   - 30 daily + 12 monthly archives (~14 snapshots average stored) — standard
   - 90 daily + 12 monthly — conservative, 3× storage cost
   - GDPR deletion clock — do client's T&Cs specify a retention maximum? If yes, invert the policy (shortest allowed).

   Recommendation: **30 daily + 12 monthly** unless GDPR/legal counsel says otherwise.

3. **UptimeRobot account** (Wave 3 #12):
   - Free tier (5-min checks) — adequate for pre-launch
   - Paid Pro ($7/mo) — 1-min checks + multi-region + SMS — recommended at/post launch
   - Who owns the account: Yordan personal? Client (клиентката) corporate? New P2P dedicated?

   Recommendation: **Client corporate account**, Yordan admin seat, free tier until launch, upgrade Day 1 of real-money operations.

4. **Emergency contact list** (Wave 2 #11 runbook):
   - Primary: Yordan
   - Secondary (when Yordan unreachable): mentor? client technical contact?
   - On-call rotation or best-effort?

   Recommendation: document current best-effort model explicitly in the runbook. Formalise to an on-call only at real-money scale.

5. **Admin team 2FA** (deferred from Phase 1):
   - Blocked from being a Phase 4 item but surfaces now via P4-Q1.
   - Trigger from DECISIONS.md is "admin team > 2 people OR external finding". Phase 4 is kind of an external finding.

   Recommendation: **bring 2FA forward** as Wave 5 (Day 5). Laravel packages exist (Laravel Sanctum + TOTP). 2–3 days. Blocks launch? Only if mentor agrees.

---

## §5 Pre-deploy checklist (for Wave-N implementation commits)

Each Wave will produce its own commits following HANDOFF §11:

```
audit(phase4): step N — short imperative description
feat(ops):     — operational code / config
chore(ops):    — maintenance items
fix(ops):      — production infrastructure fixes
docs(phase4):  — runbook / audit-report / README edits
```

Production-affecting commits must flag side-effect in body:

```
CAUTION: this changes /etc/ssh/sshd_config — requires `systemctl reload sshd`
to take effect; test from a second SSH session BEFORE disconnecting the first.
```

Per CLAUDE.md: `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>` on every commit.

---

## §5b Retracted / closed findings during Step 0

### P4-R1 — Ledger reconciliation mismatch ERROR lines (RETRACTED — false positive)

During H1 investigation, `storage/logs/laravel.log` revealed 5 consecutive nights (20–24 April) of `production.ERROR: Ledger mismatch for user #2/3: available+reserved: expected=8650.00, actual=5000.00; invested: expected=1500.00, actual=2000.00` (and similar for user #3 with ~5620 EUR delta). Initial interpretation: active wallet vs transaction-history corruption of ~9k EUR total. Elevated to CRITICAL "P4-R1" in working notes.

**Retracted** after user clarified: server date is set to a fake future value (2026-04-24) for time-skew testing while underlying transaction history was captured under a different clock. Reconcile's expected-vs-actual comparison fires against the fake-current-time view and produces artificial mismatches. This is expected behavior in the test environment; not a real corruption.

**Carried forward (non-Phase 4):** once the pre-launch data wipe happens (see P4-Q1 above), these historical ERROR lines will be purged along with the test wallets. A post-wipe smoke-test run of `ledger:reconcile --notify` should produce zero ERROR rows against the clean baseline — add that check to the pre-launch checklist acceptance criteria.

### P4-H1 — Closed (see §2.1 above for resolution details)

No fix committed; verified via `schedule:test` at 14:29 UTC.

---

## §6 Executive summary

**Step 0 surfaced 14 findings** — 2 CRITICAL, 4 HIGH, 5 MEDIUM, 3 LOW — across the infrastructure audit scope (deploy state, schedulers, queues, backups, security hardening, web/DB/logs, Laravel health).

**Most significant discoveries:**
- **P4-Q1 (CRITICAL):** the default seed admin account `admin@p2p.com / password` is still active and was used for a real admin login during the audit. Documented intent to fix (docx §14) was not executed.
- **P4-H1 (CRITICAL):** all three scheduled commands — F1 late detection, F2 buyback eligibility, ledger reconcile — have never successfully run in 16 days of production uptime. Code deployed, crons registered, but `platform_metrics` is empty and the health endpoint returns HTTP 503.
- **P4-B1 (HIGH):** backup posture is Hetzner Cloud Backups only (7-day same-vendor full-VM snapshots). No logical MySQL dumps, no off-vendor copy, no tested restore, no runbook.
- **P4-SSH1 + P4-SSH3 (HIGH + HIGH):** SSH still accepts passwords for `yordan`, and there's no SSH key configured for `yordan` — active brute-force attempts visible in `auth.log` in real time.

**The good news:** Phase 1–3 code discipline held. Deployed codebase (`2dc533f`) matches latest main and migrations are all applied. Platform settings are correctly seeded (F1 grace=10, F2 buyback defaults, F4 fees OFF by default). Storage permissions, setgid bits, TLS, UFW, and fail2ban (for the sshd portion) are all correctly configured. Security headers exist but with 3 gaps from the documented spec.

**Recommended execution order** is **Waves 1 → 4** (see §3), starting with the cron-visibility fix (P4-S1) to unlock P4-H1 diagnosis, then admin cleanup (P4-Q1), then SSH hardening (P4-SSH3 + P4-SSH1). Waves 2–3 address backups and monitoring; Wave 4 closes deploy procedure.

**v1 ship-readiness per Step 0:** NOT ready. Two CRITICAL blockers (Q1 + H1) + active brute-force on SSH + zero tested backup recovery path. All are fixable within 1–2 days of focused work once scope is approved.

**Phase 4 will continue** after user + mentor approve §3 execution order and §4 decisions.

---

*End of Phase 4 Step 0 Infrastructure Audit report. Step 1–5 pending.*
