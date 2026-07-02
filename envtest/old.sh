set -euo pipefail
read_env() { grep "^$1=" .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'"; }
DB_HOST=$(read_env DB_HOST)
DB_PORT=$(read_env DB_PORT)
echo "REACHED-END old"
