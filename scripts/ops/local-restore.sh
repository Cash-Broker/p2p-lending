#!/bin/bash
#
# P2P Lending — restore from local encrypted backup.
#
# DESTRUCTIVE: overwrites the production database.
# Confirmation required (type YES in caps).
#
# Usage on server:
#     sudo /usr/local/bin/p2p-restore /var/backups/mysql/2026-04-25.sql.gz.enc
#
# With no argument — lists available backups and exits.
#
# Read /docs/runbooks/backup-restore-bg.md for the full Bulgarian runbook.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/p2p-lending}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/mysql}"
FILE="${1:-}"

if [ -z "$FILE" ]; then
    echo "Usage: sudo p2p-restore <path-to-encrypted-backup>"
    echo ""
    echo "Recent backups (newest first):"
    ls -lht "$BACKUP_DIR"/*.sql.gz.enc 2>/dev/null | head -10 || echo "(none found)"
    exit 1
fi

if [ ! -f "$FILE" ]; then
    echo "ERROR: File not found: $FILE" >&2
    exit 1
fi

if [ ! -f "$APP_DIR/.env" ]; then
    echo "ERROR: .env not found at $APP_DIR/.env" >&2
    exit 1
fi

read_env() {
    grep "^$1=" "$APP_DIR/.env" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'"
}

DB_HOST=$(read_env DB_HOST)
DB_PORT=$(read_env DB_PORT)
DB_DATABASE=$(read_env DB_DATABASE)
DB_USERNAME=$(read_env DB_USERNAME)
DB_PASSWORD=$(read_env DB_PASSWORD)
BACKUP_PASS=$(read_env BACKUP_ENCRYPTION_PASS)
DB_PORT="${DB_PORT:-3306}"

if [ -z "$BACKUP_PASS" ]; then
    echo "ERROR: BACKUP_ENCRYPTION_PASS missing from .env." >&2
    echo "Без тази парола encrypted файлът не може да се декриптира." >&2
    exit 1
fi

echo ""
echo "=== P2P MySQL RESTORE — DESTRUCTIVE ==="
echo "Файл:     $FILE ($(du -h "$FILE" | cut -f1))"
echo "Дата:     $(stat -c '%y' "$FILE" | cut -d. -f1)"
echo "Цел:      $DB_DATABASE @ $DB_HOST:$DB_PORT"
echo "Потребител: $DB_USERNAME"
echo ""
echo "⚠️  ВНИМАНИЕ: Това ще ПРЕПИШЕ цялата текуща база '$DB_DATABASE'."
echo "    Всички текущи данни (потребители, кредити, транзакции)"
echo "    ще бъдат заменени със съдържанието на този backup."
echo ""
echo "    Препоръчително: ПРЕДИ restore направи нов backup на текущото"
echo "    състояние с: sudo /usr/local/bin/p2p-local-backup"
echo ""
read -r -p "Напиши 'YES' с главни букви за да продължиш: " CONFIRM

if [ "$CONFIRM" != "YES" ]; then
    echo "Aborted. Нищо не е променено."
    exit 1
fi

echo ""
echo "Decrypting + restoring (може да отнеме няколко секунди)..."
START=$(date +%s)

openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 -pass "pass:$BACKUP_PASS" -in "$FILE" \
    | gunzip \
    | MYSQL_PWD="$DB_PASSWORD" mysql \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USERNAME" \
        --default-character-set=utf8mb4 \
        "$DB_DATABASE"

END=$(date +%s)
echo ""
echo "✓ Restored успешно за $((END - START)) секунди."
echo ""
echo "=== СЛЕДВАЩИ СТЪПКИ ==="
echo "1. Рестартирай queue workers:"
echo "     sudo supervisorctl restart p2p-worker:*"
echo ""
echo "2. Изчисти Laravel cache:"
echo "     cd $APP_DIR && php artisan cache:clear"
echo ""
echo "3. Провери че сайтът работи:"
echo "     curl -sS https://vamaasset.bg/api/health/scheduler"
echo ""
echo "4. Ако работи — направи нов backup на восстановеното състояние:"
echo "     sudo /usr/local/bin/p2p-local-backup"
