#!/usr/bin/env bash
# W0-T3 local PostgreSQL bootstrap for Kakehashi.
# Creates role + dev/test DBs + pg_trgm, then points project .env at system PG :5432.
#
# Usage (from project root, needs sudo once):
#   bash scripts/setup-pgsql-local.sh
#
# Optional:
#   KAKEHASHI_DB_PASSWORD='your-local-only-password' bash scripts/setup-pgsql-local.sh
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${PROJECT_ROOT}/.env"
ROLE_NAME="kakehashi"
DEV_DB="kakehashi"
TEST_DB="kakehashi_test"
DB_HOST="127.0.0.1"
DB_PORT="5432"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Re-running with sudo (needed for role 'postgres')..."
  exec sudo --preserve-env=KAKEHASHI_DB_PASSWORD bash "$0" "$@"
fi

# Prefer the invoking user for file ownership of .env
TARGET_USER="${SUDO_USER:-${USER}}"

if ! command -v psql >/dev/null; then
  echo "ERROR: psql not found. Install postgresql-client-18 first." >&2
  exit 1
fi

if ! sudo -u postgres psql -d postgres -c 'SELECT 1' >/dev/null 2>&1; then
  echo "ERROR: cannot connect as postgres. Is PostgreSQL 18 running?" >&2
  echo "  sudo service postgresql start" >&2
  exit 1
fi

SERVER_VERSION="$(sudo -u postgres psql -d postgres -tAc 'SHOW server_version;')"
SERVER_VERSION_NUM="$(sudo -u postgres psql -d postgres -tAc 'SHOW server_version_num;')"
echo "PostgreSQL server: ${SERVER_VERSION}"
if (( SERVER_VERSION_NUM / 10000 != 18 )); then
  echo "ERROR: Kakehashi requires PostgreSQL 18." >&2
  exit 1
fi

if [[ -z "${KAKEHASHI_DB_PASSWORD:-}" ]]; then
  KAKEHASHI_DB_PASSWORD="$(openssl rand -base64 18 | tr -d '/+=' | head -c 24)"
  GENERATED_PASSWORD=1
else
  GENERATED_PASSWORD=0
fi

# Escape single quotes for SQL literal
sql_escape() {
  printf "%s" "$1" | sed "s/'/''/g"
}
PASS_ESC="$(sql_escape "${KAKEHASHI_DB_PASSWORD}")"

echo "Ensuring role '${ROLE_NAME}'..."
sudo -u postgres psql -v ON_ERROR_STOP=1 -d postgres <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${ROLE_NAME}') THEN
    CREATE ROLE ${ROLE_NAME} LOGIN PASSWORD '${PASS_ESC}';
  ELSE
    ALTER ROLE ${ROLE_NAME} WITH LOGIN PASSWORD '${PASS_ESC}' NOCREATEDB;
  END IF;
END
\$\$;
SQL

echo "Ensuring databases '${DEV_DB}' and '${TEST_DB}'..."
sudo -u postgres psql -v ON_ERROR_STOP=1 -d postgres <<SQL
SELECT 'CREATE DATABASE ${DEV_DB} OWNER ${ROLE_NAME}'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${DEV_DB}')\gexec
SELECT 'CREATE DATABASE ${TEST_DB} OWNER ${ROLE_NAME}'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${TEST_DB}')\gexec
ALTER DATABASE ${DEV_DB} OWNER TO ${ROLE_NAME};
ALTER DATABASE ${TEST_DB} OWNER TO ${ROLE_NAME};
SQL

echo "Enabling pg_trgm on both databases..."
sudo -u postgres psql -v ON_ERROR_STOP=1 -d "${DEV_DB}" -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"
sudo -u postgres psql -v ON_ERROR_STOP=1 -d "${TEST_DB}" -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"

echo "Verifying..."
sudo -u postgres psql -d postgres -c \
  "SELECT datname FROM pg_database WHERE datname IN ('${DEV_DB}','${TEST_DB}') ORDER BY 1;"
sudo -u postgres psql -d "${TEST_DB}" -c \
  "SELECT extname, extversion FROM pg_extension WHERE extname = 'pg_trgm';"

# Update / create .env DB block without printing the password
umask 077
if [[ ! -f "${ENV_FILE}" ]]; then
  if [[ -f "${PROJECT_ROOT}/.env.example" ]]; then
    cp "${PROJECT_ROOT}/.env.example" "${ENV_FILE}"
  else
    touch "${ENV_FILE}"
  fi
fi

KAKEHASHI_DB_PASSWORD="${KAKEHASHI_DB_PASSWORD}" \
python3 - "${ENV_FILE}" "${DB_HOST}" "${DB_PORT}" "${DEV_DB}" "${ROLE_NAME}" <<'PY'
import os
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
host, port, database, username = sys.argv[2:6]
password = os.environ["KAKEHASHI_DB_PASSWORD"]
text = path.read_text() if path.exists() else ""

def upsert(text: str, key: str, value: str) -> str:
    pattern = re.compile(rf"(?m)^{re.escape(key)}=.*$")
    line = f"{key}={value}"
    if pattern.search(text):
        return pattern.sub(line, text)
    if text and not text.endswith("\n"):
        text += "\n"
    return text + line + "\n"

for key, value in [
    ("DB_CONNECTION", "pgsql"),
    ("DB_HOST", host),
    ("DB_PORT", port),
    ("DB_DATABASE", database),
    ("DB_USERNAME", username),
    ("DB_PASSWORD", password),
]:
    text = upsert(text, key, value)

# Drop accidental sqlite leftovers
text = re.sub(r"(?m)^#\s*DB_HOST=.*\n", "", text)
text = re.sub(r"(?m)^#\s*DB_PORT=.*\n", "", text)
text = re.sub(r"(?m)^#\s*DB_DATABASE=.*\n", "", text)
text = re.sub(r"(?m)^#\s*DB_USERNAME=.*\n", "", text)
text = re.sub(r"(?m)^#\s*DB_PASSWORD=.*\n", "", text)

path.write_text(text)
print(f"Updated {path} DB_* keys (password not printed).")
PY

chown "${TARGET_USER}:${TARGET_USER}" "${ENV_FILE}"
chmod 600 "${ENV_FILE}"

echo
echo "Done."
echo "  role:     ${ROLE_NAME}"
echo "  dev DB:   ${DEV_DB}"
echo "  test DB:  ${TEST_DB}"
echo "  host:     ${DB_HOST}:${DB_PORT}"
echo "  .env:     ${ENV_FILE} (DB_* written; not committed by git)"
if [[ "${GENERATED_PASSWORD}" -eq 1 ]]; then
  echo "  password: auto-generated and stored only in .env"
else
  echo "  password: taken from KAKEHASHI_DB_PASSWORD and stored in .env"
fi
echo
echo "Next (as your normal user, no sudo):"
echo "  cd ${PROJECT_ROOT}"
echo "  php artisan config:clear"
echo "  php artisan migrate:fresh --force"
echo "  DB_DATABASE=${TEST_DB} php artisan migrate:fresh --force"
echo "  php artisan test --filter=PostgreSqlSetupTest"
