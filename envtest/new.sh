set -euo pipefail
read_env() { grep "^$1=" .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true; }
DB_HOST=$(read_env DB_HOST)
DB_PORT=$(read_env DB_PORT)
DB_PORT="${DB_PORT:-3306}"
echo "REACHED-END new: host=$DB_HOST port=$DB_PORT"
