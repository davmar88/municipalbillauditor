#!/usr/bin/env bash
#
# Add RevenueGuard (animalfarm.cc) to the server that already runs Cable
# Elite. Run as the `ubuntu` user, on a server that Cable Elite's
# `deploy/setup.sh system` has already hardened: key-only SSH, firewall,
# fail2ban, nginx, MySQL and PHP are in place.
#
# Nothing here changes Cable Elite's site, database, crontab or PHP pool.
# RevenueGuard gets its own Linux user, PHP pool, database and database user.
#
# Runs in three phases so that secrets are typed in by a human between them:
#
#   ./deploy/setup.sh prepare       # app user, database, PHP-FPM pool
#   ./deploy/setup.sh app           # clone, composer, npm build, .env skeleton
#   <edit /var/www/revenueguard/.env: MAIL_PASSWORD, ANTHROPIC_API_KEY, ...>
#   ./deploy/setup.sh finish        # permissions, migrate, nginx, scheduler, worker
#
#   ./deploy/setup.sh permissions   # only the storage permissions, again
#
# Every phase can be run again. BRANCH=... deploys a branch other than main.
# Read DEPLOY.md first.
set -euo pipefail

REPO="${REPO:-https://github.com/davmar88/municipalbillauditor.git}"
BRANCH="${BRANCH:-main}"
APP_DIR="/var/www/revenueguard"
APP_USER="revenueguard"
DOMAIN="animalfarm.cc"
# nginx's site file. It sorts after cableelite.co.za, so Cable Elite stays the
# site nginx falls back on for an address it doesn't know.
SITE="revenueguard"
# The mailbox stayed on the site's first address when it moved to
# animalfarm.cc. That address only forwards to the new one now, and is being
# cancelled: move the mailbox first.
MAIL_DOMAIN="revenueguard.co.za"
MAILBOX="info@${MAIL_DOMAIN}"    # the Xneelo mailbox the site sends from
DB_NAME="revenueguard"
DB_USER="revenueguard"
DB_PASS_FILE="$HOME/.revenueguard-db-pass"

phase="${1:-}"
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33mWARNING: %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

[[ "$(id -un)" == "ubuntu" ]] || die "run as the ubuntu user"
sudo -n true 2>/dev/null || die "ubuntu needs passwordless sudo (cloud default)"

php_version() { php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'; }

# Artisan as the app's own user, so every file it writes belongs to the
# account php-fpm and the queue worker run as.
artisan() { sudo -u "$APP_USER" HOME=/tmp php "$APP_DIR/artisan" "$@"; }

# set_env KEY VALUE: replace KEY's line in .env, or add it.
set_env() {
    local key="$1" value
    value="$(printf '%s' "$2" | sed -e 's/[\\|&]/\\&/g')"
    if grep -qE "^#?[[:space:]]*${key}=" .env; then
        sed -i -E "s|^#?[[:space:]]*${key}=.*|${key}=${value}|" .env
    else
        printf '%s=%s\n' "$key" "$2" >> .env
    fi
}

# filled KEY: true when .env gives KEY a real value. .env.example says
# MAIL_PASSWORD=null, and that is not a password.
filled() {
    local value
    value="$(sed -n "s/^${1}=//p" .env | tail -n 1)"
    value="${value#\"}"
    value="${value%\"}"
    [[ -n "$value" && "$value" != "null" ]]
}

# ---------------------------------------------------------------------------
phase_prepare() {
    log "Checking the server is the hardened Cable Elite server"
    if sudo sshd -T 2>/dev/null | grep -qi '^passwordauthentication yes'; then
        die "PasswordAuthentication is on. Fix that first (see Cable Elite's DEPLOY.md)."
    fi
    local missing=()
    for cmd in nginx mysql php composer node npm git setfacl certbot; do
        command -v "$cmd" >/dev/null || missing+=("$cmd")
    done
    [[ ${#missing[@]} -eq 0 ]] || die "missing: ${missing[*]} (Cable Elite's 'setup.sh system' installs these)"

    php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || die "PHP 8.3 or later is needed, found $(php_version)"
    for ext in pdo_mysql mbstring dom curl fileinfo openssl tokenizer ctype; do
        php -m | grep -qix "$ext" || die "the PHP extension '$ext' is missing"
    done
    node -e 'const [a, b] = process.versions.node.split(".").map(Number); process.exit((a === 20 && b >= 19) || (a === 22 && b >= 12) || a > 22 ? 0 : 1)' \
        || die "Node 20.19+ or 22.12+ is needed to build the front end, found $(node --version)"

    local v pool_dir pool
    v="$(php_version)"
    pool_dir="/etc/php/${v}/fpm/pool.d"
    pool="$pool_dir/revenueguard.conf"
    [[ -d "$pool_dir" ]] || die "no PHP-FPM pool folder at $pool_dir"

    log "Linux user '$APP_USER' (no login, no home)"
    if ! id "$APP_USER" >/dev/null 2>&1; then
        sudo useradd --system --user-group --home-dir /nonexistent --no-create-home --shell /usr/sbin/nologin "$APP_USER"
    fi

    log "MySQL database and user '$DB_USER'"
    sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    if [[ "$(sudo mysql -N -e "SELECT COUNT(*) FROM mysql.user WHERE user = '${DB_USER}' AND host = 'localhost';")" == "0" ]]; then
        # Kept in a file only you can read until 'finish', which deletes it.
        local db_pass
        db_pass="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-28)"
        ( umask 077; printf '%s' "$db_pass" > "$DB_PASS_FILE" )
        sudo mysql <<SQL
CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${db_pass}';
SQL
    else
        echo "The database user exists already: keeping its password, which is in ${APP_DIR}/.env."
    fi
    sudo mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"

    log "PHP-FPM pool (php${v}-fpm)"
    if [[ -f "$pool" ]]; then
        sudo cp -p "$pool" "${pool}.previous"
    fi
    sudo install -m 644 "$here/php-fpm/revenueguard.conf" "$pool"
    if ! sudo "php-fpm${v}" -t; then
        # Cable Elite's PHP runs in the same php-fpm: a bad pool file left
        # behind would stop it at the next reload.
        if [[ -f "${pool}.previous" ]]; then sudo mv "${pool}.previous" "$pool"; else sudo rm -f "$pool"; fi
        die "PHP-FPM rejected the RevenueGuard pool. It has been put back as it was; nothing was reloaded."
    fi
    sudo rm -f "${pool}.previous"
    sudo systemctl reload "php${v}-fpm"

    log "Prepare phase done. Next: ./deploy/setup.sh app"
}

# ---------------------------------------------------------------------------
phase_app() {
    id "$APP_USER" >/dev/null 2>&1 || die "run the prepare phase first"

    log "Clone $BRANCH into $APP_DIR"
    sudo mkdir -p "$APP_DIR"
    sudo chown ubuntu:"$APP_USER" "$APP_DIR"
    sudo chmod 2755 "$APP_DIR"
    if [[ -d "$APP_DIR/.git" ]]; then
        git -C "$APP_DIR" fetch origin "$BRANCH"
        git -C "$APP_DIR" checkout "$BRANCH"
        git -C "$APP_DIR" pull --ff-only origin "$BRANCH"
    else
        git clone --branch "$BRANCH" "$REPO" "$APP_DIR"
    fi
    cd "$APP_DIR"

    log "PHP dependencies (production)"
    composer install --no-dev --optimize-autoloader --no-interaction

    log ".env for production"
    if [[ ! -f .env ]]; then
        cp .env.example .env
    fi
    set_env APP_ENV production
    set_env APP_DEBUG false
    set_env APP_URL "https://${DOMAIN}"
    set_env LOG_LEVEL warning
    set_env LOG_STACK daily     # a file a day, kept for 14 days
    set_env DB_CONNECTION mysql
    set_env DB_HOST 127.0.0.1
    set_env DB_PORT 3306
    set_env DB_DATABASE "$DB_NAME"
    set_env DB_USERNAME "$DB_USER"
    if [[ -f "$DB_PASS_FILE" ]]; then
        set_env DB_PASSWORD "$(cat "$DB_PASS_FILE")"
    elif ! filled DB_PASSWORD; then
        die "no database password: run the prepare phase first"
    fi
    set_env SESSION_SECURE_COOKIE true
    set_env QUEUE_CONNECTION database
    set_env DB_QUEUE_RETRY_AFTER 330
    # Xneelo mail, as for Cable Elite. Their shared mail server presents a
    # certificate for its own host name, so the name check is skipped; the
    # connection is still encrypted.
    set_env MAIL_MAILER smtp
    set_env MAIL_HOST "smtp.${MAIL_DOMAIN}"
    set_env MAIL_PORT 465
    set_env MAIL_VERIFY_PEER false
    set_env MAIL_USERNAME "$MAILBOX"
    set_env MAIL_FROM_ADDRESS "\"${MAILBOX}\""
    if ! filled MAIL_PASSWORD; then
        set_env MAIL_PASSWORD ""    # blank, for you to fill in
    fi
    if ! filled CONTACT_EMAIL; then
        set_env CONTACT_EMAIL "$MAILBOX"
    fi
    grep -qE '^APP_KEY=.+' .env || php artisan key:generate --force
    # Readable by the app's user and by you, by nobody else (not www-data).
    sudo chown ubuntu:"$APP_USER" .env
    sudo chmod 640 .env

    log "Front-end build"
    # The build reads the routes; cached ones would be the old version's.
    php artisan config:clear
    php artisan route:clear
    npm ci
    npm run build

    cat <<MSG

App phase done. NOW, BY HAND, edit ${APP_DIR}/.env and fill in:

    MAIL_PASSWORD=          (the ${MAILBOX} mailbox's password)
    ANTHROPIC_API_KEY=      (translates chat; without it the Translate button is off)
    COMPANY_NAME=, INFORMATION_OFFICER=   (shown in the privacy notice, with CONTACT_EMAIL=${MAILBOX})

Then: ./deploy/setup.sh finish
MSG
}

# ---------------------------------------------------------------------------
fix_permissions() {
    log "Permissions: only the app's user (and you) can touch storage and caches"
    cd "$APP_DIR" || die "app not installed: run the app phase first"
    sudo mkdir -p storage/tmp storage/app/private
    sudo chown -R ubuntu:"$APP_USER" storage bootstrap/cache
    sudo chmod -R u=rwX,g=rwX,o= storage bootstrap/cache
    sudo find storage bootstrap/cache -type d -exec chmod g+s {} +
    # Default ACLs (-d) keep files created later writable by both users; a
    # one-off grant isn't enough (Cable Elite learned that the hard way).
    sudo setfacl -R  -m "u:${APP_USER}:rwX,u:ubuntu:rwX" storage bootstrap/cache
    sudo setfacl -Rd -m "u:${APP_USER}:rwX,u:ubuntu:rwX,g::rwX,o::---" storage bootstrap/cache
    sudo chown ubuntu:"$APP_USER" .env
    sudo chmod 640 .env
}

phase_finish() {
    cd "$APP_DIR" || die "app not installed: run the app phase first"
    filled MAIL_PASSWORD || die "MAIL_PASSWORD is still empty in .env: sign-up e-mails can't be sent without it"
    for k in ANTHROPIC_API_KEY COMPANY_NAME INFORMATION_OFFICER CONTACT_EMAIL; do
        filled "$k" || warn "$k is empty in .env"
    done

    fix_permissions
    local v
    v="$(php_version)"

    log "Database"
    artisan migrate --force
    # Lays out the game's valley; safe to run on every deploy.
    artisan game:setup

    log "Production caches"
    artisan config:cache
    artisan route:cache
    artisan view:cache

    log "nginx site"
    local site="/etc/nginx/sites-available/${SITE}" enabled="/etc/nginx/sites-enabled/${SITE}"
    if sudo grep -qs 'managed by Certbot' "$site"; then
        # Certbot has added HTTPS to the installed copy. Installing ours over
        # it would put the site back on plain HTTP.
        warn "Certbot manages ${site} now, so it is left as it is. Copy any change in deploy/nginx/${DOMAIN}.conf across by hand."
    else
        if [[ -f "$site" ]]; then
            sudo cp -p "$site" "${site}.previous"
        fi
        sudo install -m 644 "$here/nginx/${DOMAIN}.conf" "$site"
        sudo ln -sf "$site" "$enabled"
        if ! sudo nginx -t; then
            # A broken site left enabled would make Cable Elite's next
            # reload, or Certbot's renewal, fail too.
            if [[ -f "${site}.previous" ]]; then sudo mv "${site}.previous" "$site"; else sudo rm -f "$enabled" "$site"; fi
            die "nginx rejected the RevenueGuard site. It has been put back as it was; nothing was reloaded."
        fi
        sudo rm -f "${site}.previous"
    fi
    sudo systemctl reload nginx

    log "Scheduler (/etc/cron.d/revenueguard)"
    sudo install -m 644 -o root -g root "$here/cron/revenueguard" /etc/cron.d/revenueguard

    log "Queue worker"
    sudo install -m 644 "$here/systemd/revenueguard-queue.service" /etc/systemd/system/revenueguard-queue.service
    sudo systemctl daemon-reload
    sudo systemctl enable revenueguard-queue
    sudo systemctl restart revenueguard-queue

    sudo systemctl reload "php${v}-fpm"
    rm -f "$DB_PASS_FILE"

    cat <<MSG

Finish phase done. Remaining steps (see DEPLOY.md):

  1. Check it before DNS moves:   ${APP_DIR}/deploy/verify.sh http://${DOMAIN} 127.0.0.1
  2. Point DNS for ${DOMAIN} and www at this server, in Xneelo's control panel.
  3. sudo certbot --nginx -d ${DOMAIN} -d www.${DOMAIN}   # once DNS resolves here
  4. Register on https://${DOMAIN}, then: ${APP_DIR}/deploy/artisan app:make-staff you@example.com
MSG
}

case "$phase" in
    prepare)     phase_prepare ;;
    app)         phase_app ;;
    finish)      phase_finish ;;
    permissions) fix_permissions; echo "Done." ;;
    *) sed -n '2,21p' "$0"; exit 1 ;;
esac
