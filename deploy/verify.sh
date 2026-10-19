#!/usr/bin/env bash
# Smoke check after every deploy: the pages a visitor hits first, against the
# real database, and (on the server) the permissions that fail silently.
#
#   deploy/verify.sh                                # https://animalfarm.cc
#   deploy/verify.sh http://animalfarm.cc 127.0.0.1  # before DNS points here
set -uo pipefail

BASE="${1:-https://animalfarm.cc}"
IP="${2:-}"
APP_DIR="/var/www/revenueguard"
FAIL=0

host="${BASE#*://}"
host="${host%%/*}"
port=443
[ "${BASE%%://*}" = "http" ] && port=80
resolve=()
# --noproxy: a proxy would fetch the real site, wherever DNS points.
[ -n "$IP" ] && resolve=(--noproxy '*' --resolve "${host}:${port}:${IP}")

check() {   # check <path> <expected status> [must-contain]
    local path="$1" want="$2" needle="${3:-}"
    local body status
    body=$(curl -sS --max-time 20 "${resolve[@]}" -w '\n%{http_code}' "${BASE}${path}" 2>/dev/null) || { echo "  FAIL ${path}: could not be fetched"; FAIL=1; return; }
    status="${body##*$'\n'}"
    body="${body%$'\n'*}"

    if [ "$status" != "$want" ]; then
        echo "  FAIL ${path}: HTTP ${status}, expected ${want}"
        FAIL=1
        return
    fi
    # A here-string, not a pipe: grep -q stops reading early, and under
    # pipefail that would make a match look like a miss.
    if [ -n "$needle" ] && ! grep -qF -- "$needle" <<< "$body"; then
        echo "  FAIL ${path}: HTTP ${status} but \"${needle}\" is missing"
        FAIL=1
        return
    fi
    echo "  ok   ${path} (${status})"
}

echo "Checking ${BASE}${IP:+ via ${IP}}"
check /                 200 'lang="en-ZA"'
check /privacy          200 "POPIA"
check /sitemap.xml      200 "<loc>"
check /robots.txt       200 "User-agent"
check /login            200
check /register         200
check /spot-the-edit    200
check /play             302          # signs you in first
check /nope-not-a-page  404

# On the server itself.
if [ -d "$APP_DIR" ] && id revenueguard >/dev/null 2>&1; then
    echo "Checking the server"
    for dir in storage/logs storage/framework/views storage/framework/cache storage/app/private storage/tmp bootstrap/cache; do
        probe="${APP_DIR}/${dir}/.verify-$$"
        if sudo -u revenueguard touch "$probe" 2>/dev/null; then
            sudo -u revenueguard rm -f "$probe"
            echo "  ok   revenueguard can write ${dir}"
        else
            echo "  FAIL revenueguard cannot write ${dir} (uploads and caches will 500): ${APP_DIR}/deploy/setup.sh permissions"
            FAIL=1
        fi
    done

    # Cable Elite's PHP runs as www-data: it must not be able to read
    # RevenueGuard's secrets or players' files.
    if sudo -u www-data test -r "${APP_DIR}/.env"; then
        echo "  FAIL www-data can read .env: ${APP_DIR}/deploy/setup.sh permissions"
        FAIL=1
    else
        echo "  ok   www-data cannot read .env"
    fi
    if sudo -u www-data test -x "${APP_DIR}/storage"; then
        echo "  FAIL www-data can open storage/ (players' files): ${APP_DIR}/deploy/setup.sh permissions"
        FAIL=1
    else
        echo "  ok   www-data cannot open storage/"
    fi

    if systemctl is-active --quiet revenueguard-queue; then
        echo "  ok   queue worker running"
    else
        echo "  FAIL queue worker not running: sudo systemctl status revenueguard-queue"
        FAIL=1
    fi
    if [ -f /etc/cron.d/revenueguard ]; then
        echo "  ok   scheduler installed"
    else
        echo "  FAIL /etc/cron.d/revenueguard is missing"
        FAIL=1
    fi
fi

if [ "$FAIL" -ne 0 ]; then
    echo "SMOKE CHECK FAILED: fix or roll back now."
    exit 1
fi
echo "All good."
