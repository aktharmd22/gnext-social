#!/usr/bin/env bash
#
# First-time install on shared hosting.
#
# Run once, over SSH, from anywhere:
#
#   bash ~/gnextsocial/deploy/install.sh
#
# Layout it produces:
#
#   ~/gnextsocial/                        the application, never web-served
#   ~/domains/<DOMAIN>/public_html/       front controller + asset symlinks only
#
# Re-running is safe: it will not overwrite an existing .env, and it never
# drops data. Use update.sh for subsequent deploys.
#
set -euo pipefail

DOMAIN="${DOMAIN:-gnextsocial.gnext.space}"
APP_ROOT="${APP_ROOT:-$HOME/gnextsocial}"
DOCROOT="${DOCROOT:-$HOME/domains/$DOMAIN/public_html}"
PHP="${PHP:-php}"

say  () { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
ok   () { printf '    \033[32mok\033[0m   %s\n' "$1"; }
warn () { printf '    \033[33mnote\033[0m %s\n' "$1"; }
die  () { printf '\n\033[31mstopped:\033[0m %s\n\n' "$1" >&2; exit 1; }

# ---------------------------------------------------------------- checks -----
say "Checking the host"

[ -d "$APP_ROOT" ] || die "No application at $APP_ROOT. Clone it first:
    git clone https://github.com/aktharmd22/gnext-social.git $APP_ROOT"

[ -d "$DOCROOT" ] || die "No web root at $DOCROOT. Set DOCROOT= if the domain differs."

command -v "$PHP" >/dev/null || die "No php binary. Set PHP=/usr/bin/php8.3 or similar."

PHP_VERSION="$("$PHP" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
"$PHP" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' \
    || die "PHP $PHP_VERSION is too old; this needs 8.2 or newer.
    Look for another binary: ls /usr/bin/php* /opt/alt/php*/usr/bin/php
    Then re-run with PHP=/path/to/php8.3 bash $0"
ok "php $PHP_VERSION at $(command -v "$PHP")"

for ext in pdo_mysql mbstring openssl tokenizer xml ctype bcmath fileinfo curl zip gd; do
    "$PHP" -m | grep -qix "$ext" || die "PHP extension '$ext' is missing. Enable it in hPanel > PHP Configuration."
done
ok "all required PHP extensions present"

command -v composer >/dev/null || die "composer not found on PATH."
ok "composer at $(command -v composer)"

cd "$APP_ROOT"

# ------------------------------------------------------------ dependencies ---
say "Installing PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
ok "vendor/ installed without dev packages"

# ------------------------------------------------------------------- .env ----
say "Environment"
if [ -f .env ]; then
    ok ".env already exists, left untouched"
else
    cp deploy/env.production.example .env
    "$PHP" artisan key:generate --force
    warn ".env created from the template and APP_KEY generated."
    warn "EDIT IT NOW before continuing: database credentials and APP_URL."
    warn "  nano $APP_ROOT/.env"
    warn ""
    warn "APP_KEY encrypts every stored Meta access token. If you ever replace"
    warn "it, every connected Page must be reconnected. Back it up."
    exit 0
fi

grep -q '^APP_KEY=base64:' .env || die "APP_KEY is empty. Run: $PHP artisan key:generate --force"

APP_URL="$(grep -E '^APP_URL=' .env | cut -d= -f2- | tr -d '"'"'"'')"
case "$APP_URL" in
    https://*) ok "APP_URL is $APP_URL" ;;
    *) die "APP_URL is '$APP_URL'. It must be https://$DOMAIN -- Meta rejects a
    non-HTTPS OAuth redirect URI, so connecting a Page would fail." ;;
esac

# --------------------------------------------------------------- database ----
say "Database"
"$PHP" artisan migrate --force
ok "migrations applied"

# ------------------------------------------------------------------ build ----
say "Front-end assets"
if [ -f public/build/manifest.json ]; then
    ok "public/build present, came down with the repository"
elif command -v npm >/dev/null; then
    npm ci --omit=dev 2>/dev/null || npm install
    npm run build
    ok "assets built on the server"
else
    die "No public/build, and no npm on this host to make one.
    On your machine:
        npm run build && git add public/build
        git commit -m 'build assets' && git push
    Here:
        cd $APP_ROOT && git pull && bash deploy/install.sh"
fi

# ------------------------------------------------------------- permissions ---
say "Storage"
mkdir -p storage/framework/{cache/data,sessions,views} storage/logs storage/app/public bootstrap/cache
chmod -R 775 storage bootstrap/cache
ok "storage and bootstrap/cache writable"

# ---------------------------------------------------------------- web root ---
say "Wiring $DOCROOT"

if [ -n "$(ls -A "$DOCROOT" 2>/dev/null | grep -v '^\.well-known$' || true)" ]; then
    BACKUP="$DOCROOT/../public_html.before-gnext.$(date +%Y%m%d%H%M%S)"
    warn "public_html is not empty; moving what is there to:"
    warn "  $BACKUP"
    mkdir -p "$BACKUP"
    find "$DOCROOT" -mindepth 1 -maxdepth 1 ! -name '.well-known' -exec mv {} "$BACKUP"/ \;
fi

cp deploy/public_html/index.php  "$DOCROOT/index.php"
cp deploy/public_html/.htaccess  "$DOCROOT/.htaccess"
cp public/robots.txt             "$DOCROOT/robots.txt"  2>/dev/null || true
cp public/favicon.ico            "$DOCROOT/favicon.ico" 2>/dev/null || true

# Symlinks are preferred -- a git pull then updates the served assets with no
# second step -- but this host may forbid them. Test rather than assume: a
# broken link here serves a blank, unstyled application.
if ln -sfn "$APP_ROOT/public/build" "$DOCROOT/build" 2>/dev/null && [ -d "$DOCROOT/build" ]; then
    ln -sfn "$APP_ROOT/public/fonts" "$DOCROOT/fonts"
    ok "assets linked (a git pull updates them with no further step)"
    LINKED=yes
else
    warn "symlinks are not permitted here; copying assets instead"
    warn "update.sh re-copies them, so keep deploying with that script"
    rm -rf "$DOCROOT/build" "$DOCROOT/fonts"
    cp -r public/build "$DOCROOT/build"
    cp -r public/fonts "$DOCROOT/fonts"
    ok "assets copied"
    LINKED=no
fi

# Uploaded media is a real directory inside the web root, never a symlink.
# Meta fetches every image and video by URL, so this path has to be reachable
# with certainty -- a link that silently failed would mean every publish fails
# at the last step, reported by Meta rather than caught here.
mkdir -p "$DOCROOT/storage"
chmod 755 "$DOCROOT/storage"

if grep -q '^GNEXT_PUBLIC_DISK_ROOT=' .env; then
    sed -i "s|^GNEXT_PUBLIC_DISK_ROOT=.*|GNEXT_PUBLIC_DISK_ROOT=$DOCROOT/storage|" .env
else
    printf '\nGNEXT_PUBLIC_DISK_ROOT=%s\n' "$DOCROOT/storage" >> .env
fi
ok "media disk points at $DOCROOT/storage"

ok "front controller and rewrite rules in place"

# ------------------------------------------------------------------ caches ---
say "Caching configuration"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
ok "config, routes and views cached"

# ------------------------------------------------------------------- cron ----
say "Cron"
if command -v crontab >/dev/null; then
    CRON_HOW="Add these two lines with:  crontab -e"
else
    CRON_HOW="crontab is not available over SSH on this host.
    Add these in hPanel > Advanced > Cron Jobs, both set to run every minute."
fi

cat <<CRON

    $CRON_HOW

    # Laravel scheduler: dispatches due posts, recovers missed ones,
    # checks token health, captures insights, sends the weekly digest.
    * * * * * cd $APP_ROOT && $PHP artisan schedule:run >> /dev/null 2>&1

    # Queue worker. Shared hosting kills long-running daemons, so this runs a
    # short burst each minute and exits, instead of queue:work as a daemon.
    * * * * * cd $APP_ROOT && $PHP artisan queue:work --stop-when-empty --max-time=50 --tries=3 >> storage/logs/queue.log 2>&1

CRON

# -------------------------------------------------------------- preflight ----
say "Application preflight"
"$PHP" artisan gnext:preflight || true

cat <<DONE

    Installed.

    Next, in the browser at $APP_URL :
      1. Sign in.
      2. Settings > Meta app: enter the App ID and secret.
         The redirect URI to paste into the Meta dashboard is
         $APP_URL/oauth/facebook/callback
      3. Settings > Accounts: connect the Page and its Instagram account.

    Then re-run:  $PHP artisan gnext:preflight

DONE
