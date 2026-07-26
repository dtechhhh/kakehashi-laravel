#!/usr/bin/env bash
# Local PostgreSQL bootstrap for Kakehashi (W0-T3 / W1-T4-FIX-B).
# Dual role: kakehashi_migrator (owner) + kakehashi (runtime).
# Table/sequence grants and audit_log REVOKE live in migration 000005 — not here.
#
# Usage (from project root, needs sudo once):
#   bash scripts/setup-pgsql-local.sh
#
# Optional:
#   KAKEHASHI_DB_PASSWORD='<runtime-role-password>' \
#   KAKEHASHI_MIGRATOR_PASSWORD='<migrator-role-password>' \
#   bash scripts/setup-pgsql-local.sh
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${PROJECT_ROOT}/.env"
ENV_MIGRATOR_FILE="${PROJECT_ROOT}/.env.migrator"
RUNTIME_ROLE="kakehashi"
MIGRATOR_ROLE="kakehashi_migrator"
DEV_DB="kakehashi"
TEST_DB="kakehashi_test"
DB_HOST="127.0.0.1"
DB_PORT="5432"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Re-running with sudo (needed for role 'postgres')..."
  exec sudo --preserve-env=KAKEHASHI_DB_PASSWORD,KAKEHASHI_MIGRATOR_PASSWORD bash "$0" "$@"
fi

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

# Target DB names are fixed allowlist — refuse surprises.
for name in "${DEV_DB}" "${TEST_DB}" "${RUNTIME_ROLE}" "${MIGRATOR_ROLE}"; do
  if [[ ! "${name}" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]]; then
    echo "ERROR: invalid identifier: ${name}" >&2
    exit 1
  fi
done

if [[ -z "${KAKEHASHI_DB_PASSWORD:-}" ]]; then
  KAKEHASHI_DB_PASSWORD="$(openssl rand -base64 18 | tr -d '/+=' | head -c 24)"
  GENERATED_RUNTIME_PASSWORD=1
else
  GENERATED_RUNTIME_PASSWORD=0
fi

if [[ -z "${KAKEHASHI_MIGRATOR_PASSWORD:-}" ]]; then
  KAKEHASHI_MIGRATOR_PASSWORD="$(openssl rand -base64 18 | tr -d '/+=' | head -c 24)"
  GENERATED_MIGRATOR_PASSWORD=1
else
  GENERATED_MIGRATOR_PASSWORD=0
fi

sql_escape() {
  printf "%s" "$1" | sed "s/'/''/g"
}
RUNTIME_PASS_ESC="$(sql_escape "${KAKEHASHI_DB_PASSWORD}")"
MIGRATOR_PASS_ESC="$(sql_escape "${KAKEHASHI_MIGRATOR_PASSWORD}")"

echo "Ensuring roles '${MIGRATOR_ROLE}' (owner) and '${RUNTIME_ROLE}' (runtime)..."
sudo -u postgres psql -v ON_ERROR_STOP=1 -d postgres <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${MIGRATOR_ROLE}') THEN
    CREATE ROLE ${MIGRATOR_ROLE} LOGIN PASSWORD '${MIGRATOR_PASS_ESC}' NOSUPERUSER NOCREATEDB NOCREATEROLE;
  ELSE
    ALTER ROLE ${MIGRATOR_ROLE} WITH LOGIN PASSWORD '${MIGRATOR_PASS_ESC}' NOSUPERUSER NOCREATEDB NOCREATEROLE;
  END IF;

  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${RUNTIME_ROLE}') THEN
    CREATE ROLE ${RUNTIME_ROLE} LOGIN PASSWORD '${RUNTIME_PASS_ESC}' NOSUPERUSER NOCREATEDB NOCREATEROLE;
  ELSE
    ALTER ROLE ${RUNTIME_ROLE} WITH LOGIN PASSWORD '${RUNTIME_PASS_ESC}' NOSUPERUSER NOCREATEDB NOCREATEROLE;
  END IF;
END
\$\$;

-- Runtime must not inherit migrator privileges via membership.
REVOKE ${MIGRATOR_ROLE} FROM ${RUNTIME_ROLE};
SQL

echo "Ensuring databases '${DEV_DB}' and '${TEST_DB}' owned by '${MIGRATOR_ROLE}'..."
sudo -u postgres psql -v ON_ERROR_STOP=1 -d postgres <<SQL
SELECT 'CREATE DATABASE ${DEV_DB} OWNER ${MIGRATOR_ROLE}'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${DEV_DB}')\gexec
SELECT 'CREATE DATABASE ${TEST_DB} OWNER ${MIGRATOR_ROLE}'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${TEST_DB}')\gexec
ALTER DATABASE ${DEV_DB} OWNER TO ${MIGRATOR_ROLE};
ALTER DATABASE ${TEST_DB} OWNER TO ${MIGRATOR_ROLE};
GRANT CONNECT ON DATABASE ${DEV_DB} TO ${RUNTIME_ROLE};
GRANT CONNECT ON DATABASE ${TEST_DB} TO ${RUNTIME_ROLE};
GRANT CONNECT ON DATABASE ${DEV_DB} TO ${MIGRATOR_ROLE};
GRANT CONNECT ON DATABASE ${TEST_DB} TO ${MIGRATOR_ROLE};
SQL

for db in "${DEV_DB}" "${TEST_DB}"; do
  echo "Validating target + ownership handoff on '${db}'..."
  # One transaction: current_database() is proven inside the same session that reassigns.
  sudo -u postgres psql -v ON_ERROR_STOP=1 -d "${db}" <<SQL
BEGIN;

DO \$\$
BEGIN
  IF current_database() NOT IN ('${DEV_DB}', '${TEST_DB}') THEN
    RAISE EXCEPTION 'refusing to REASSIGN OWNED on unexpected database %', current_database();
  END IF;
END
\$\$;

-- Old installs: objects owned by runtime role move to migrator.
REASSIGN OWNED BY ${RUNTIME_ROLE} TO ${MIGRATOR_ROLE};

GRANT USAGE ON SCHEMA public TO ${RUNTIME_ROLE};
REVOKE CREATE ON SCHEMA public FROM ${RUNTIME_ROLE};
GRANT ALL ON SCHEMA public TO ${MIGRATOR_ROLE};

CREATE EXTENSION IF NOT EXISTS pg_trgm;

COMMIT;
SQL
done

echo "Verifying..."
sudo -u postgres psql -d postgres -c \
  "SELECT datname, pg_catalog.pg_get_userbyid(datdba) AS owner
   FROM pg_database
   WHERE datname IN ('${DEV_DB}','${TEST_DB}')
   ORDER BY 1;"
sudo -u postgres psql -d "${TEST_DB}" -c \
  "SELECT extname, extversion FROM pg_extension WHERE extname = 'pg_trgm';"
sudo -u postgres psql -d postgres -tAc \
  "SELECT 'runtime_is_member_of_migrator=' || pg_has_role('${RUNTIME_ROLE}', '${MIGRATOR_ROLE}', 'member');"

# Runtime .env — never write migrator password here
umask 077
if [[ ! -f "${ENV_FILE}" ]]; then
  if [[ -f "${PROJECT_ROOT}/.env.example" ]]; then
    cp "${PROJECT_ROOT}/.env.example" "${ENV_FILE}"
  else
    touch "${ENV_FILE}"
  fi
fi

KAKEHASHI_DB_PASSWORD="${KAKEHASHI_DB_PASSWORD}" \
python3 - "${ENV_FILE}" "${DB_HOST}" "${DB_PORT}" "${DEV_DB}" "${RUNTIME_ROLE}" <<'PY'
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

# Strip any migrator secrets that may have been added by hand to runtime .env
for key in (
    "DB_MIGRATOR_USERNAME",
    "DB_MIGRATOR_PASSWORD",
    "DB_MIGRATOR_URL",
    "DB_MIGRATOR_HOST",
    "DB_MIGRATOR_PORT",
    "DB_MIGRATOR_DATABASE",
):
    text = re.sub(rf"(?m)^{re.escape(key)}=.*\n?", "", text)

text = re.sub(r"(?m)^#\s*DB_HOST=.*\n", "", text)
text = re.sub(r"(?m)^#\s*DB_PORT=.*\n", "", text)
text = re.sub(r"(?m)^#\s*DB_DATABASE=.*\n", "", text)
text = re.sub(r"(?m)^#\s*DB_USERNAME=.*\n", "", text)
text = re.sub(r"(?m)^#\s*DB_PASSWORD=.*\n", "", text)

path.write_text(text)
print(f"Updated {path} runtime DB_* keys (password not printed).")
PY

# CLI-only migrator credentials
KAKEHASHI_MIGRATOR_PASSWORD="${KAKEHASHI_MIGRATOR_PASSWORD}" \
python3 - "${ENV_MIGRATOR_FILE}" "${MIGRATOR_ROLE}" <<'PY'
import os
import sys
from pathlib import Path

path = Path(sys.argv[1])
username = sys.argv[2]
password = os.environ["KAKEHASHI_MIGRATOR_PASSWORD"]
path.write_text(
    "\n".join(
        [
            f"DB_MIGRATOR_USERNAME={username}",
            f"DB_MIGRATOR_PASSWORD={password}",
            "",
        ]
    )
)
print(f"Wrote {path} (mode will be 600; password not printed).")
PY

chown "${TARGET_USER}:${TARGET_USER}" "${ENV_FILE}" "${ENV_MIGRATOR_FILE}"
chmod 600 "${ENV_FILE}" "${ENV_MIGRATOR_FILE}"

echo
echo "Done."
echo "  migrator role: ${MIGRATOR_ROLE} (database owner)"
echo "  runtime role:  ${RUNTIME_ROLE}"
echo "  dev DB:        ${DEV_DB}"
echo "  test DB:       ${TEST_DB}"
echo "  host:          ${DB_HOST}:${DB_PORT}"
echo "  .env:          runtime DB_* only (no migrator password)"
echo "  .env.migrator: CLI migrator credentials (gitignored, mode 600)"
if [[ "${GENERATED_RUNTIME_PASSWORD}" -eq 1 ]]; then
  echo "  runtime password:  auto-generated → .env only"
else
  echo "  runtime password:  from KAKEHASHI_DB_PASSWORD → .env only"
fi
if [[ "${GENERATED_MIGRATOR_PASSWORD}" -eq 1 ]]; then
  echo "  migrator password: auto-generated → .env.migrator only"
else
  echo "  migrator password: from KAKEHASHI_MIGRATOR_PASSWORD → .env.migrator only"
fi
echo
echo "Next (as your normal user, no sudo) — load migrator for CLI only:"
echo "  cd ${PROJECT_ROOT}"
echo "  set -a && source .env.migrator && set +a"
echo "  php artisan config:clear"
echo "  php artisan migrate:fresh --database=pgsql_migrator --force"
echo "  DB_DATABASE=${TEST_DB} php artisan migrate:fresh --database=pgsql_migrator --force"
echo "  php artisan test"
echo "Table grants / audit_log REVOKE come from migration 000005 (not this script)."
