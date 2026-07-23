#!/usr/bin/env bash
# W0-T4 local Redis 7.4.x bootstrap for Kakehashi.
# Installs Redis from the official packages.redis.io APT repo (pinned 7.4.x),
# configures localhost bind + noeviction + maxmemory 1gb, enables the service.
#
# Usage (from project root; needs sudo once for APT + redis.conf):
#   bash scripts/setup-redis-local.sh
#
# Docs: https://redis.io/docs/latest/operate/oss_and_stack/install/install-stack/apt/
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Re-running with sudo (needed for APT and /etc/redis)..."
  exec sudo bash "$0" "$@"
fi

export DEBIAN_FRONTEND=noninteractive

detect_codename() {
  if command -v lsb_release >/dev/null 2>&1; then
    lsb_release -cs
    return
  fi
  # Fallback when helpers are not yet installed / APT is broken.
  if [[ -r /etc/os-release ]]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    echo "${VERSION_CODENAME:-jammy}"
    return
  fi
  echo "jammy"
}

echo "==> Repair Redis APT list first (must precede any apt-get)"
# A broken multi-line redis.list blocks ALL apt operations until rewritten.
codename="$(detect_codename)"
# Single-line entry only — newline after the codename makes APT report
# "Malformed entry ... (Component)" (common paste error from multi-line docs).
printf 'deb [signed-by=/usr/share/keyrings/redis-archive-keyring.gpg] https://packages.redis.io/deb %s main\n' \
  "${codename}" > /etc/apt/sources.list.d/redis.list
echo "Wrote /etc/apt/sources.list.d/redis.list:"
cat -A /etc/apt/sources.list.d/redis.list

echo "==> Install helpers"
apt-get install -y lsb-release curl gpg

echo "==> Official Redis APT key"
curl -fsSL https://packages.redis.io/gpg \
  | gpg --batch --yes --dearmor -o /usr/share/keyrings/redis-archive-keyring.gpg
chmod 644 /usr/share/keyrings/redis-archive-keyring.gpg

# Re-write list with confirmed codename after helpers exist.
codename="$(detect_codename)"
printf 'deb [signed-by=/usr/share/keyrings/redis-archive-keyring.gpg] https://packages.redis.io/deb %s main\n' \
  "${codename}" > /etc/apt/sources.list.d/redis.list
echo "Confirmed /etc/apt/sources.list.d/redis.list:"
cat -A /etc/apt/sources.list.d/redis.list

echo "==> Pin Redis packages to 7.4.x"
cat > /etc/apt/preferences.d/redis <<'EOF'
Package: redis redis-server redis-sentinel redis-tools
Pin: version 6:7.4.*
Pin-Priority: 1001
EOF

apt-get update
echo "==> apt-cache policy redis-server"
apt-cache policy redis-server | sed -n '1,60p'

candidate="$(apt-cache policy redis-server | awk '/Candidate:/{candidate=$2} END{print candidate}')"
echo "Candidate redis-server: ${candidate}"
case "${candidate}" in
  6:7.4.*) ;;
  *)
    echo "ERROR: expected candidate pin 6:7.4.*, got ${candidate}" >&2
    echo "Check /etc/apt/preferences.d/redis and packages.redis.io reachability." >&2
    exit 1
    ;;
esac

echo "==> Install/upgrade Redis + PHP 8.4 phpredis"
apt-get install -y \
  "redis-server=${candidate}" \
  "redis-tools=${candidate}" \
  php8.4-redis

echo "==> redis-server --version"
redis-server --version
dpkg -l redis-server redis-tools | awk 'NR==1 || /^ii/'

installed="$(dpkg-query -W -f='${Version}\n' redis-server)"
case "${installed}" in
  6:7.4.*) ;;
  *)
    echo "ERROR: redis-server package still ${installed}, expected 6:7.4.*" >&2
    exit 1
    ;;
esac

if ! php -m | grep -x 'redis' >/dev/null; then
  echo "ERROR: PHP 8.4 redis extension is not active." >&2
  exit 1
fi

CONF=/etc/redis/redis.conf
if [[ ! -f "${CONF}" ]]; then
  echo "ERROR: ${CONF} not found after install." >&2
  exit 1
fi

echo "==> Backup redis.conf (no-clobber)"
cp --no-clobber --archive "${CONF}" "${CONF}.w0t4.bak" || true

echo "==> Enforce bind / protected-mode / maxmemory / maxmemory-policy (single active line each)"
tmp="$(mktemp)"
# Drop active (non-comment) lines for the four directives, then append the locked values once.
awk '
  /^[[:space:]]*#/ { print; next }
  /^[[:space:]]*bind[[:space:]]+/ { next }
  /^[[:space:]]*protected-mode[[:space:]]+/ { next }
  /^[[:space:]]*maxmemory[[:space:]]+/ { next }
  /^[[:space:]]*maxmemory-policy[[:space:]]+/ { next }
  { print }
' "${CONF}" > "${tmp}"

{
  cat "${tmp}"
  echo ""
  echo "# Kakehashi W0-T4 local/production-like defaults (managed by scripts/setup-redis-local.sh)"
  echo "bind 127.0.0.1 ::1"
  echo "protected-mode yes"
  echo "maxmemory 1gb"
  echo "maxmemory-policy noeviction"
} > "${CONF}"
rm -f "${tmp}"

echo "==> Enable + restart redis-server"
if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files redis-server.service >/dev/null 2>&1; then
  systemctl enable redis-server
  systemctl restart redis-server
  systemctl --no-pager status redis-server || true
else
  service redis-server restart || true
fi

# Brief wait for listen
for _ in 1 2 3 4 5; do
  if redis-cli ping >/dev/null 2>&1; then
    break
  fi
  sleep 0.5
done

echo "==> Runtime verification"
redis-cli ping
redis-cli INFO server | grep '^redis_version:' || true
redis-cli CONFIG GET bind
redis-cli CONFIG GET protected-mode
redis-cli CONFIG GET maxmemory
redis-cli CONFIG GET maxmemory-policy
if command -v ss >/dev/null 2>&1; then
  ss -ltn '( sport = :6379 )' || ss -ltn | grep 6379 || true
fi

version="$(redis-cli INFO server | awk -F: '/^redis_version:/{print $2}' | tr -d '\r')"
policy="$(redis-cli CONFIG GET maxmemory-policy | awk 'NR==2{print}')"
maxmem="$(redis-cli CONFIG GET maxmemory | awk 'NR==2{print}')"
bind="$(redis-cli CONFIG GET bind | awk 'NR==2{print}')"

echo "version=${version} policy=${policy} maxmemory=${maxmem} bind=${bind}"

case "${version}" in
  7.*) ;;
  *)
    echo "ERROR: expected Redis 7.x, got ${version}" >&2
    exit 1
    ;;
esac

if [[ "${policy}" != "noeviction" ]]; then
  echo "ERROR: expected maxmemory-policy noeviction, got ${policy}" >&2
  exit 1
fi

if [[ "${maxmem}" != "1073741824" ]]; then
  echo "ERROR: expected maxmemory 1073741824 (1gb), got ${maxmem}" >&2
  exit 1
fi

if [[ "${bind}" != "127.0.0.1 ::1" && "${bind}" != "127.0.0.1" ]]; then
  echo "ERROR: expected local bind, got ${bind}" >&2
  exit 1
fi

echo "OK: Redis ${version} ready for Kakehashi W0-T4."
