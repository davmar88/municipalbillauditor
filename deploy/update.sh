#!/usr/bin/env bash
#
# Deploy the latest code to RevenueGuard. Run as the ubuntu user, on the
# server:
#
#   /var/www/revenueguard/deploy/update.sh            # main
#   BRANCH=some-branch /var/www/revenueguard/deploy/update.sh
#
# Code is pushed to GitHub from your PC; the server only pulls.
set -euo pipefail

# Everything runs from main() so bash has read the whole script before
# `git pull` replaces it.
main() {
    local app_dir="/var/www/revenueguard"
    local branch="${BRANCH:-main}"
    local v
    v="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"

    [[ "$(id -un)" == "ubuntu" ]] || { echo "run as the ubuntu user" >&2; exit 1; }
    cd "$app_dir"

    echo "==> Pull ${branch}"
    git fetch origin "$branch"
    git checkout "$branch"
    git pull --ff-only origin "$branch"

    echo "==> Dependencies and front end"
    composer install --no-dev --optimize-autoloader --no-interaction
    # The build reads the routes; cached ones would be the old version's.
    deploy/artisan config:clear
    deploy/artisan route:clear
    npm ci
    npm run build

    echo "==> Database"
    deploy/artisan migrate --force
    # Lays out Hollowmere on the first deploy; adds only what is missing after.
    deploy/artisan game:setup
    # The public updates page, from the commits just deployed.
    deploy/artisan game:changelog

    echo "==> Caches, worker and PHP"
    deploy/artisan config:cache
    deploy/artisan route:cache
    deploy/artisan view:cache
    sudo systemctl restart revenueguard-queue    # or it keeps running old code
    # Live updates: Reverb runs the code it started with, so it restarts too.
    if systemctl list-unit-files revenueguard-reverb.service >/dev/null 2>&1; then
        sudo systemctl restart revenueguard-reverb
    fi
    sudo systemctl reload "php${v}-fpm"          # clears opcache

    echo "==> Smoke check"
    deploy/verify.sh
}

main "$@"
