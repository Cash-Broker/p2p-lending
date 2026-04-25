#!/bin/bash
#
# P2P Lending — daily local MySQL backup with encryption + rotation + Telegram.
#
# Pipeline: mysqldump → gzip → openssl AES-256 → /var/backups/mysql/
# - Transactionally consistent dump (--single-transaction)
# - Encrypted at rest (aes-256-cbc with PBKDF2)
# - 30-day rotation (older files auto-deleted)
# - Symlink "latest.sql.gz.enc" → newest backup (for easy SCP from laptop)
# - Telegram notification on success (INFO tier — silent)
# - Telegram notification on failure (CRITICAL tier — push)
#
# Schedule: daily at 02:30 UTC (BEFORE ledger:reconcile at 03:00).
# Cron entry (added during deploy):
#     30 2 * * * /usr/local/bin/p2p-local-backup
#
# Read /docs/runbooks/backup-restore-bg.md for the operator-facing
# Bulgarian runbook (download via SCP, restore procedure, etc.).

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/p2p-lending}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/mysql}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
LOG_FILE="${LOG_FILE:-/var/log/p2p-local-backup.log}"

# --- Read DB + backup encryption + Telegram credentials from .env ---
read_env() {
    grep "^$1=" "$APP_DIR/.env" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'"
}

DB_HOST=$(read_env DB_HOST)
DB_PORT=$(read_env DB_PORT)
DB_DATABASE=$(read_env DB_DATABASE)
DB_USERNAME=$(read_env DB_USERNAME)
DB_PASSWORD=$(read_env DB_PASSWORD)
BACKUP_PASS=$(read_env BACKUP_ENCRYPTION_PASS)
TG_TOKEN=$(read_env TELEGRAM_BOT_TOKEN)
TG_CHAT=$(read_env TELEGRAM_CHAT_ID)

DB_PORT="${DB_PORT:-3306}"

# --- Validate required ---
for v in DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD BACKUP_PASS; do
    if [ -z "${!v:-}" ]; then
        echo "ERROR: $v missing in $APP_DIR/.env" >&2
        if [ -n "${TG_TOKEN:-}" ] && [ -n "${TG_CHAT:-}" ]; then
            CONFIG_ERR_MSG="🔴 <b>BACKUP CONFIG ERROR</b>

$v missing in .env. Daily backup will not run."
            curl -sS -X POST "https://api.telegram.org/bot${TG_TOKEN}/sendMessage" \
                --data-urlencode "chat_id=${TG_CHAT}" \
                --data-urlencode "parse_mode=HTML" \
                --data-urlencode "text=$CONFIG_ERR_MSG" \
                > /dev/null 2>&1 || true
        fi
        exit 1
    fi
done

# --- Setup ---
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
mkdir -p "$(dirname "$LOG_FILE")"

TIMESTAMP=$(date -u +%Y-%m-%d)
OUTFILE="$BACKUP_DIR/${TIMESTAMP}.sql.gz.enc"
LOG_TS=$(date -u +%Y-%m-%dT%H:%M:%SZ)

log() {
    echo "[$LOG_TS] $1" >> "$LOG_FILE"
}

notify_telegram() {
    [ -z "$TG_TOKEN" ] || [ -z "$TG_CHAT" ] && return 0
    curl -sS -X POST "https://api.telegram.org/bot${TG_TOKEN}/sendMessage" \
        --data-urlencode "chat_id=${TG_CHAT}" \
        --data-urlencode "parse_mode=HTML" \
        --data-urlencode "disable_notification=$2" \
        --data-urlencode "text=$1" \
        > /dev/null 2>&1 || true
}

START=$(date +%s)
log "Backup started → $OUTFILE"

# --- Backup pipeline: dump → gzip → encrypt ---
if MYSQL_PWD="$DB_PASSWORD" mysqldump \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USERNAME" \
        --single-transaction \
        --routines \
        --triggers \
        --events \
        --hex-blob \
        --default-character-set=utf8mb4 \
        "$DB_DATABASE" 2>> "$LOG_FILE" \
    | gzip -c \
    | openssl enc -aes-256-cbc -salt -pbkdf2 -iter 100000 -pass "pass:$BACKUP_PASS" \
    > "$OUTFILE.tmp" \
    && mv "$OUTFILE.tmp" "$OUTFILE"; then

    chmod 600 "$OUTFILE"

    SIZE_BYTES=$(stat -c%s "$OUTFILE")
    SIZE_HUMAN=$(du -h "$OUTFILE" | cut -f1)

    ln -sfn "$(basename "$OUTFILE")" "$BACKUP_DIR/latest.sql.gz.enc"

    END=$(date +%s)
    DURATION=$((END - START))
    log "Backup OK: $OUTFILE ($SIZE_HUMAN, ${DURATION}s)"

    REMOVED=$(find "$BACKUP_DIR" -maxdepth 1 -name "*.sql.gz.enc" -type f -mtime +"$RETENTION_DAYS" 2>/dev/null | wc -l)
    if [ "$REMOVED" -gt 0 ]; then
        find "$BACKUP_DIR" -maxdepth 1 -name "*.sql.gz.enc" -type f -mtime +"$RETENTION_DAYS" -delete 2>/dev/null
        log "Rotation: removed $REMOVED file(s) older than $RETENTION_DAYS days"
    fi

    TOTAL_FILES=$(ls -1 "$BACKUP_DIR"/*.sql.gz.enc 2>/dev/null | wc -l)

    SUCCESS_MSG="🟡 <b>Daily Backup готов</b>

📁 Файл: <code>${TIMESTAMP}.sql.gz.enc</code>
📦 Размер: ${SIZE_HUMAN}
⏱ Време: ${DURATION}s
🗂 Общо в /var/backups/mysql/: ${TOTAL_FILES} файла (max ${RETENTION_DAYS})

<b>За теглене на лаптоп:</b>
<code>scp -i ~/.ssh/id_ed25519_p2p yordan@178.104.78.0:/var/backups/mysql/latest.sql.gz.enc ~/p2p-backups/</code>"
    notify_telegram "$SUCCESS_MSG" "true"
    exit 0
else
    rm -f "$OUTFILE.tmp"
    log "Backup FAILED — check $LOG_FILE for mysqldump errors"
    FAIL_MSG="🔴 <b>BACKUP FAILED</b>

mysqldump или encryption pipeline пропадна.

<b>Виж логовете на сървъра:</b>
<code>tail -50 ${LOG_FILE}</code>

Проверете състоянието на MySQL и свободното място на диска."
    notify_telegram "$FAIL_MSG" "false"
    exit 1
fi
