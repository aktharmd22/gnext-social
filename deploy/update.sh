#!/usr/bin/env bash
#
# Every deploy after the first.
#
#   bash ~/gnextsocial/deploy/update.sh
#
# Puts the site into maintenance mode, pulls, migrates, rebuilds caches, and
# comes back up. The maintenance window is the point: config:cache rewrites the
# compiled config while requests are still arriving otherwise, and a request
# that lands mid-rewrite gets a half-built container.
#
set -euo pipefail

# Wherever install.sh put it. Override with APP_ROOT= if you moved it.
APP_ROOT="${APP_ROOT:-$HOME/gnextsocial}"
[ -d "$APP_ROOT" ] || APP_ROOT="$HOME/domains/gnextsocial.gnext.space/app"
PHP="${PHP:-php}"

say () { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
ok  () { printf '    \033[32mok\033[0m   %s\n' "$1"; }

cd "$APP_ROOT"

# However this exits, the site must come back up.
trap '"$PHP" artisan up >/dev/null 2>&1 || true' EXIT

say "Maintenance mode"
"$PHP" artisan down --retry=15 --render="errors::503" 2>/dev/null \
    || "$PHP" artisan down --retry=15
ok "site is showing a maintenance page"

say "Pulling"
BEFORE="$(git rev-parse --short HEAD)"
git pull --ff-only
AFTER="$(git rev-parse --short HEAD)"
ok "$BEFORE -> $AFTER"

if [ "$BEFORE" = "$AFTER" ]; then
    ok "already up to date, but continuing so caches are rebuilt"
fi

say "Dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
ok "vendor/ in step with composer.lock"

say "Assets"
if [ -f public/build/manifest.json ] && ! git diff --quiet "$BEFORE" "$AFTER" -- public/build 2>/dev/null; then
    ok "build/ came down with the pull"
elif command -v npm >/dev/null && ! git diff --quiet "$BEFORE" "$AFTER" -- resources package-lock.json 2>/dev/null; then
    npm ci --omit=dev 2>/dev/null || npm install
    npm run build
    ok "assets rebuilt"
else
    ok "assets unchanged"
fi

# If assets were copied rather than linked, the pull updated public/build in
# the repository but not the copy the web server actually serves.
say "Web root"
DOCROOT="${DOCROOT:-$HOME/domains/gnextsocial.gnext.space/public_html}"
if [ -d "$DOCROOT/build" ] && [ ! -L "$DOCROOT/build" ]; then
    rm -rf "$DOCROOT/build" "$DOCROOT/fonts"
    cp -r public/build "$DOCROOT/build"
    cp -r public/fonts "$DOCROOT/fonts"
    ok "assets re-copied (this host does not allow symlinks)"
else
    ok "assets are symlinked, nothing to copy"
fi

cp deploy/public_html/index.php "$DOCROOT/index.php"
cp deploy/public_html/.htaccess "$DOCROOT/.htaccess"
ok "front controller and rewrite rules refreshed"

say "Database"
"$PHP" artisan migrate --force
ok "migrations applied"

say "Caches"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
ok "rebuilt"

# Workers hold the old code in memory. Without this the next queued publish
# runs against the code from before the pull.
say "Queue"
"$PHP" artisan queue:restart
ok "workers told to finish and exit"

say "Preflight"
"$PHP" artisan gnext:preflight || true

printf '\n    Deployed %s.\n\n' "$AFTER"
