# Phase 4 Handoff Document

**Audience:** the next Claude Code session (or human developer) picking up Phase 4 — Infrastructure Audit.

**Author:** the Phase 1/2/3 audit session, on completion of Phase 3 merge (`868c9da` on `main`).

---

## 1. Project context

**Platform:** P2P lending marketplace at **vamaasset.bg**. Investors fund loans originated by licensed financial institutions (originators). The platform is the intermediary — a pure accounting + ledger system. Built in Laravel 13 + Filament 5.4 + Vue 3.

**Solo developer:** Yordan Yordanov (CashBroker / itcashbroker@gmail.com). Works in a single-developer Claude Code flow — each phase scoped, audited, and merged explicitly via the user. Mentor (different session) approves key architectural decisions.

**Virtual money ledger model:** the platform is strictly an accounting mirror. ALL money inside the platform is virtual — the admin ("клиентката") holds REAL money in an external business bank account. Every money-movement action in the platform corresponds to (or anticipates) a real bank transfer the admin handles externally. Deposits wire to admin's bank first; admin verifies and credits the platform. Withdrawals approve on the platform → admin wires externally. Buyback / early-repayment / repayment same pattern — admin verifies the off-platform transfer, clicks Execute in Filament. See DECISIONS.md F4-01 for the full model.

**Pre-launch status:** v1 has NOT yet been deployed to production. All implementation + audit work to date is on `main` awaiting a first production deploy. Phase 4 is where the first real production handling work starts.

---

## 2. Cumulative progress

| Phase | Scope | Status | Base commit |
|---|---|---|---|
| F1 | Late/Default automation | ✅ merged | `47d90fb` |
| F2 | Buyback Guarantee | ✅ merged | `9efbbf5` |
| F3 | Early Repayment | ✅ merged | `a30fafc` |
| F4 | Fees Infrastructure (flag off by default) | ✅ merged | `aa71a2c` |
| F4.5 | Filament `Forms\Get` hotfix | ✅ merged | `cadc3a8` |
| F5 | APR / ГПР Display | ✅ merged | `fa069d0` |
| Audit 1 | Security (previous session) | ✅ merged | baseline |
| Audit 2 | Financial Correctness (pure-math Python oracle + wallet integrity scenarios) | ✅ merged | `8edc3ac` |
| Audit 3 | Business Logic & Lifecycle | ✅ merged | `868c9da` |
| **Audit 4** | **Infrastructure** | **⏭️ this phase** | starting from `868c9da` |

All 6 F-phase implementations + 3 audit phases live on main. No phase has been reverted. Every merge was a clean fast-forward (no merge commits).

---

## 3. Current platform state

**Test totals (main @ `868c9da`):**

| Suite | Pass | Skip | Assertions |
|---|---|---|---|
| Unit + Feature (default `php artisan test`) | 455 | 4 | 1300 |
| Audit (`--testsuite=Audit`, opt-in) | 175 | 0 | 1823 |
| **Total** | **630** | **4** | **3123** |

**Zero regressions across 6 merge cycles** (F4 Step 1 → F4 Batch A → F4 Batch B → F4 docs → F4.5 hotfix → F5 Step 1 → F5 Batch A+B → F5 docs → Audit 2 oracle → Audit 2 wallet → Audit 2 enhancements → P3-F4 → P3-F5 → Phase 3 matrix → Phase 3 finalize).

**Zero CRITICAL findings** in any audit phase. Audit 2 found 2 real platform bugs (HIGH `InvestmentService` transition race + CRITICAL pro-rata drift clamp) — both fixed in-phase. Audit 3 found 9 findings (3 MEDIUM all fixed, 1 LOW-MED + 5 LOW documented or deferred).

**v1.1 commitments carried forward** (documented in DECISIONS.md + per-phase audit reports):

| v1.1 Item | Source | Est. effort | Trigger to bring forward |
|---|---|---|---|
| Pro-rata redesign (cumulative-aware distribution) — replace clamp | P2-01 | 2–3 days | clamp freq > 1/week OR investor complaint OR scale > 500 loans / 200 investors |
| Automated `cancelled` loan status + `CancelRefundExecutionService` | P3-01 | 1–2 days | any real partial-funded stall case |
| Observability dashboard (stale FUNDED + stale withdrawals + clamp frequency) | P3-F6 + P3-F7 + P2-01 metric | ~2 hours | post-launch operator feedback |
| Emit `loan_events` on InvestmentService + Filament admin transitions | P3-F8 + P3-F9 | ~2 hours | investor feedback that timeline is incomplete |
| Admin 2FA | Phase 1 | 2–3 days | admin team > 2 people OR pen-test finding |

---

## 4. Phase 4 scope — Infrastructure Audit

**This is operational work, not code review.** Covers the production server, backup strategy, process reliability, security hardening, monitoring, and deployment procedure.

### 4.1 Production environment current state (to be documented)

- **Host:** Hetzner CCX23 (existing server — provisioned during an earlier session, before this audit series).
- **Stack:** LEMP (Linux + nginx + MySQL + PHP-FPM).
- **SSL:** A+ rating on SSL Labs (from an earlier setup session).
- **SSH access patterns:** key-based auth (assumption — must be verified).
- **Current Laravel deploy state:** unknown. Code may be at an older tag — needs verification vs `main@868c9da`.

**Action items (Phase 4 Step 0):**
- Document actual nginx config, MySQL version + tuning, PHP version, Composer / NPM versions, system user layout.
- Capture `/etc/os-release`, `uname -a`, installed package list.

### 4.2 Backup strategy

**Current state:** likely NONE or ad-hoc. **CRITICAL GAP for a financial platform.**

Required for launch:
- **Automated daily MySQL dumps** — logical backup via `mysqldump` with proper flags (`--single-transaction --routines --triggers --events`). Scheduled via cron; dump to local disk + rsync to off-site location.
- **Off-site backup storage** — decision needed: Hetzner Storage Box (cheapest, same vendor), Wasabi S3-compatible, or another Hetzner region. Budget + retention policy to agree with mentor.
- **Backup restoration testing** — **NEVER TESTED**. A backup that has never been restored is not a backup. Monthly restore test into a scratch DB + automated verification query is the v1 ask.
- **Recovery procedure documentation** — step-by-step runbook: what to do when MySQL dies, what to do when the VM dies, what to do when someone truncates a table. Stored in `docs/runbooks/` or an ops document.

Expected finding severity: the absence of any of these is **HIGH**. Restoration testing is arguably **CRITICAL** for a financial platform.

### 4.3 Process reliability

- **Queue workers** — Laravel `queue:work` runs notifications (F1 LoanWentLate, F2 LoanBoughtBack, F3 EarlyRepaymentReceived, password reset emails). Currently scheduled via... unknown. Must be managed by **Supervisor** in production (README mentions this is required but doesn't confirm it's set up).
- **Cron schedulers** — Laravel `schedule:run` needs a single OS cron entry (`* * * * * php artisan schedule:run`). Three scheduled commands rely on this: `ledger:reconcile` (03:00), `loans:process-late` (03:30, now with the Phase 3 P3-F5 auto-repay third pass), `loans:detect-buyback-eligible` (03:45).
- **Process monitoring** — Supervisor auto-restart on crash is table-stakes. Beyond that, external process monitoring (systemd watchdog or similar) would help detect "worker silently dead" scenarios.

**Action items:** SSH and check `supervisorctl status`, `systemctl list-timers`, `crontab -l -u www-data` (or whichever user).

### 4.4 Security hardening

- **Firewall (UFW)** — should allow only 22 (SSH), 80 (HTTP → redirect to HTTPS), 443 (HTTPS). Everything else denied.
- **fail2ban** — SSH brute-force protection. Configure jail for sshd + nginx-auth if Filament admin is exposed.
- **SSH key-only auth** — `PasswordAuthentication no` in `/etc/ssh/sshd_config`. Verify.
- **System user permissions review** — `www-data` (or Laravel user) should have minimal filesystem access: read on app code, read+write on `storage/` + `bootstrap/cache/`, nothing else. No sudo.

**Action items:** SSH in and check `ufw status`, `fail2ban-client status`, `cat /etc/ssh/sshd_config | grep -E 'PasswordAuthentication|PermitRootLogin'`, `id www-data`.

### 4.5 Monitoring

- **External uptime monitoring** — UptimeRobot / Healthchecks.io / Pingdom pointed at `https://vamaasset.bg/api/health/scheduler` (public endpoint that the platform already exposes; covers both F1 late cron + F2 buyback cron via worst-of-two aggregate).
- **Log rotation** — `/var/log/nginx/*.log`, `storage/logs/laravel.log`, MySQL slow query log. `logrotate` configured, or are logs silently growing until they fill the disk?
- **Alert channels** — at minimum, email on UptimeRobot alert. Telegram integration is a v1.1 nice-to-have.
- **Laravel log level** — production should be `warning` or higher, NOT `debug`. Verify `.env`'s `LOG_LEVEL`.

**Action items:** check `/etc/logrotate.d/`, verify `.env` is production-tuned (no `APP_DEBUG=true`, no `LOG_LEVEL=debug`), confirm external monitor is configured.

### 4.6 Deployment procedure

v1 launch must have a written, tested deploy procedure. Options:

- **Zero-downtime pattern** — atomic symlink swap (Envoyer-style) OR Laravel Deployer scripts. Builds release in `/var/www/releases/{timestamp}`, swaps `current` symlink, reloads PHP-FPM. Rollback = swap symlink back.
- **Migration strategy** — forward-only migrations. No `down()` bodies for data migrations (per Laravel discipline + platform's append-only ledger model). Pre-migrate review: ensure migrations are additive (new columns with defaults, new tables, new indexes) — never destructive.
- **Rollback procedure** — code rollback = symlink swap. DB rollback = restore from backup (last-resort; incident-severity). A tested "restore backup + revert code" runbook exists?
- **Post-deploy smoke tests** — small scripted checklist: admin can log in; investor can reach `/marketplace`; `/api/health/scheduler` returns 200; one test loan end-to-end.
- **Documentation** — the entire procedure as a runbook. Ideally `docs/runbooks/deploy.md`.

Expected finding severity: absence of ANY of the above is **HIGH** (MEDIUM at best if partially in place).

---

## 5. Established patterns from F1–F5 + audit phases

Phase 4 inherits — does not reinvent — the following patterns. Any operational work must align:

1. **bcmath everywhere for money.** Storage `decimal(12,2)`; intermediate scale 10 for ratios; final scale 2 for amounts. See CLAUDE.md Financial Logic.
2. **`DB::transaction` + `lockForUpdate`** for every money-movement service method. Infrastructure work that touches DB writes (backup verification queries, migration runners) follows this.
3. **Triple-layer defense** (DB triggers + CHECK constraints + app-level guards) on append-only tables (`transactions`, `loan_events`, `audit_logs`). Operational scripts MUST NOT bypass these via raw SQL — see P3-F3 documentation.
4. **Append-only ledger** — transactions + loan_events immutable. Backups preserve this; restores must replay the full history or restore the whole snapshot, never partial rows.
5. **Whitelist metadata sanitisation** — `LoanEventResource::PUBLIC_METADATA_KEYS`. Any new infrastructure-surfaced metadata (e.g. deployment markers in loan_events) needs whitelisting.
6. **Admin-triggered financial operations** (human-in-the-loop). F2 buyback, F3 early-repay, F4 withdrawal fee — all admin-clicks after verifying real-world bank state. Infrastructure does NOT auto-move real money.
7. **Money first, emails second** — notifications dispatched AFTER `DB::transaction` commits. Deploy scripts that test notification paths honour this.
8. **Empirical SQL log tests** as regression guards — services that batch multiple column writes into a single UPDATE have a dedicated test checking `DB::getQueryLog`. Infrastructure changes that touch these patterns must preserve the test.
9. **Last-investor-remainder with P2-01 clamp patch.** `WalletService::creditAvailableFromInvested` clamps `invested` at 0 when pro-rata drift would underflow. Logs warning + writes `platform_metrics` counter. Backup/restore workflows should preserve the `prorata_clamps_total` metric.
10. **Virtual ledger model** — admin holds real money external. Infrastructure backups protect the LEDGER; the bank statement is the source-of-truth for the real money. Reconciliation scripts compare the two.

---

## 6. Step 0 Discovery tasks for the new session

**READ-ONLY investigation.** No server changes, no migrations, no production-side edits until findings are reported and scope confirmed with user.

### SSH documentation sweep

Tell the user the specific commands you need them to run (or run yourself if they've granted SSH access). At minimum:

```sh
# Basic system state
uname -a
cat /etc/os-release
uptime
df -h
free -h

# User context
id
who
last -n 20              # recent logins

# Scheduler inventory
crontab -l              # current user's cron
crontab -l -u www-data  # Laravel user's cron (likely the relevant one)
systemctl list-timers --all | head -20

# Process inventory
supervisorctl status
systemctl list-units --type=service --state=running | grep -E 'nginx|mysql|mariadb|php|redis|supervisor|queue'

# Firewall + SSH
sudo ufw status verbose
sudo fail2ban-client status
sudo fail2ban-client status sshd
grep -E "^(PasswordAuthentication|PermitRootLogin|Port|AllowUsers)" /etc/ssh/sshd_config

# Backups
ls -la /var/backups/ /opt/backups/ /home/*/backups/ 2>/dev/null
find / -name "*.sql.gz" -mtime -7 2>/dev/null | head -10
crontab -l | grep -i backup
systemctl list-timers | grep -i backup

# Log rotation
ls /etc/logrotate.d/
cat /etc/logrotate.d/nginx 2>/dev/null
cat /etc/logrotate.d/mysql 2>/dev/null
# Laravel log
ls -lh /var/www/*/storage/logs/laravel.log 2>/dev/null

# SSL
sudo certbot certificates 2>/dev/null
systemctl list-timers | grep certbot

# Recent auth
sudo tail -50 /var/log/auth.log

# MySQL configuration
sudo cat /etc/mysql/my.cnf 2>/dev/null
sudo cat /etc/mysql/mysql.conf.d/mysqld.cnf 2>/dev/null
sudo mysql -e "SHOW VARIABLES LIKE 'innodb_%';" | head -20

# Laravel deploy state
cd /var/www/<app> && git log --oneline -3
cd /var/www/<app> && php artisan --version
cd /var/www/<app> && cat .env | grep -E '^(APP_ENV|APP_DEBUG|LOG_LEVEL|DB_|QUEUE_|SESSION_)'
```

### Deliverable

Write **`AUDIT_REPORT_PHASE4.md`** mirroring the F1/F2/F3/P2/P3 audit-report shape:

- Methodology (this handoff §6 commands).
- Per-topic findings (server state, backup, process, security, monitoring, deploy).
- Severity categorisation (CRITICAL / HIGH / MEDIUM / LOW).
- Proposed fix order + effort estimates.
- Mentor-decision checkpoints (things the user/mentor must agree on, not Claude).

Report Step 0 findings to the user before starting any fix work. Infrastructure fixes in a production environment are higher-stakes than code changes — the user/mentor decides scope before execution.

---

## 7. Phase 4 special considerations

- **Mentor will be hands-on.** More collaborative than previous audit phases. Expect the user to jump in with decisions, paste terminal output, or run commands themselves rather than granting Claude remote shell.
- **Production server access.** Some tasks require SSH into vamaasset.bg's Hetzner box. Claude Code has no inherent SSH. The user will either:
  - Paste command output into the chat.
  - Run commands themselves and report results.
  - Grant an SSH key if the workflow allows.
  Be explicit about what you need and why — do not assume access you don't have.
- **Some tasks are human-only.** Configuring an UptimeRobot account, agreeing on backup retention policy, naming emergency contacts — these belong to the user + mentor, not the agent.
- **Bulgarian context.** Admin ("клиентката") is Bulgarian, non-technical. Ops runbooks she'll read must be in clear Bulgarian where user-facing. Internal-only ops docs can be English.
- **Data sensitivity.** Production DB contains real investor data (pre-launch, so minimal — but any test fixtures must be treated as production-sensitive).
- **NOT ALLOWED by default without explicit user approval:**
  - Any production-DB write (including `php artisan migrate` or seed commands).
  - Any server restart (nginx / MySQL / PHP-FPM).
  - Any deploy.
  - Any destructive operation on a real server.

---

## 8. Branch setup

```sh
cd /path/to/p2p-lending
git checkout main
git pull origin main     # confirm at 868c9da or newer
git checkout -b feature/phase4-infrastructure-audit
```

Commit convention:
```
audit(phase4): step N — short imperative description
feat(ops): — operational setup / config changes
chore(ops): — maintenance items
fix(ops): — production fixes
```

---

## 9. Deferred items from Phases 1–3 (reference only)

Not Phase 4 work. Listed so the new session doesn't accidentally pick these up:

- **v1.1 pro-rata redesign** — DECISIONS.md P2-01. Trigger conditions listed; 2–3 days.
- **v1.1 cancelled loan status + `CancelRefundExecutionService`** — DECISIONS.md P3-01. 1–2 days.
- **v1.1 observability dashboard** — consolidated P2-01 clamp freq + P3-F6 stale FUNDED + P3-F7 stale withdrawals. ~2 hours.
- **v1.1 loan_events completeness** — P3-F8 (InvestmentService) + P3-F9 (Filament admin). ~2 hours combined.
- **Admin 2FA** — deferred from Phase 1 security audit. Compensating controls (login alerts, strong-password policy, audit logs, SameSite=strict) in place. Trigger: admin team > 2 OR external finding.

---

## 10. Known residual — not Phase 4 blocker

All pre-existing from earlier phases; none touched by audit work:

- **13 stale local branches** on the developer machine (`audit/phase-2-financial`, `claude/awesome-jepsen`, `claude/exciting-swanson`, `claude/flamboyant-shirley`, `claude/funny-dhawan`, `claude/inspiring-liskov`, `claude/interesting-hermann`, `claude/nifty-kirch`, `claude/stoic-tesla`, `dashboard`, `feature/late-default-automation`, `fillament`, `security/phase-1-remediation`). All behind main after merges.
- **2 stale remote branches** (`origin/dashboard`, `origin/fillament`).
- **`stoic-tesla` worktree** at `.claude/worktrees/stoic-tesla` on `claude/stoic-tesla` — contains a 95 KB Node script + 67 KB Bulgarian legal PDF. Unrelated to F/audit phases. Tracked since HANDOFF_F4.md §10.
- **Empty worktree leftover directories** (`goofy-archimedes-b2be8f/`, `reverent-merkle-671306/`) — Windows junction quirk, clear on shell-session end.

Housekeeping candidates (not launch-blocking). Could batch into a `chore(repo): stale-branch cleanup` commit when convenient.

---

## 11. Commit conventions (Phase 4 specific)

```
audit(phase4): step N — short imperative description
feat(ops): — operational code / config changes (e.g. adding a deploy script)
chore(ops): — maintenance items (e.g. log rotation config)
fix(ops): — production infrastructure fixes (e.g. UFW rule correction)
docs(phase4): — runbook / AUDIT_REPORT_PHASE4.md / README Operations edits
```

Multi-paragraph commit body describing WHAT changed + WHY + notable decisions, ending with:

```
Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
```

Pre-deploy / production-affecting commits MUST explicitly flag the side-effect in the commit body (e.g. "CAUTION: this changes /etc/ssh/sshd_config — requires `systemctl reload sshd` to take effect; test from a second SSH session BEFORE disconnecting the first").

---

## 12. Recommended first message to user (for new session)

> "Read HANDOFF_PHASE4.md first. After reading, begin Step 0 Discovery of current production state.
>
> NOTE: Phase 4 requires actual production server access or local test environment mimicking production. User may need to run commands manually (SSH, etc.). Be explicit about what's needed and why."

---

## 13. Context handoff — session baton

- **Main at:** `868c9da` (Phase 3 merge final commit).
- **Audit report pattern** well-established: see `AUDIT_REPORT_PHASE2.md` and `AUDIT_REPORT_PHASE3.md` for the expected shape.
- **DECISIONS.md** running log — any Phase 4 architectural decision should land there (e.g. P4-01, P4-02, ...).
- **CLAUDE.md operational procedures section** expanded during Phase 3 — Phase 4 runbooks fit here naturally or as a new `docs/runbooks/` subtree.

---

## 14. Good luck 👋

Phase 4 is where "it works on my machine" becomes "it runs reliably for Bulgarian investors on day one of launch". The previous phases made the code correct; Phase 4 makes the deploy correct. Take the time to document findings carefully — the backup/restore runbook is the single most important artefact of this phase.

— the Phase 1/2/3 audit session (wrapped on `868c9da`)
