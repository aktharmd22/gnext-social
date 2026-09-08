#!/usr/bin/env bash
#
# Run this on the server BEFORE installing. It changes nothing; it only reports
# what is present, so the install can be adjusted to what the host actually
# offers rather than what it is assumed to offer.
#
#   bash preflight.sh
#
set -u

line () { printf '%-28s %s\n' "$1" "$2"; }
have () { command -v "$1" >/dev/null 2>&1 && echo "$(command -v "$1")" || echo "MISSING"; }

echo
echo "=== host ==="
line "user@host"        "$(whoami)@$(hostname)"
line "home"             "$HOME"
line "disk free"        "$(df -h "$HOME" 2>/dev/null | awk 'NR==2 {print $4" free of "$2}')"

echo
echo "=== php ==="
line "php (cli)"        "$(have php)"
line "php version"      "$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo '?')"
echo "  other php binaries on PATH:"
ls /usr/bin/php* /opt/alt/php*/usr/bin/php 2>/dev/null | head -12 | sed 's/^/    /'

echo
echo "  required extensions:"
for ext in pdo_mysql mbstring openssl tokenizer xml ctype json bcmath fileinfo curl zip gd intl; do
    php -m 2>/dev/null | grep -qix "$ext" \
        && printf '    ok      %s\n' "$ext" \
        || printf '    MISSING %s\n' "$ext"
done

echo
echo "  functions this app needs:"
for fn in proc_open exec symlink; do
    php -r "exit(function_exists('$fn') && !in_array('$fn', array_map('trim', explode(',', (string) ini_get('disable_functions')))) ? 0 : 1);" 2>/dev/null \
        && printf '    ok       %s\n' "$fn" \
        || printf '    DISABLED %s   <- see notes\n' "$fn"
done
line "  memory_limit"   "$(php -r 'echo ini_get("memory_limit");' 2>/dev/null)"
line "  upload_max"     "$(php -r 'echo ini_get("upload_max_filesize");' 2>/dev/null)"
line "  post_max"       "$(php -r 'echo ini_get("post_max_size");' 2>/dev/null)"
line "  max_execution"  "$(php -r 'echo ini_get("max_execution_time");' 2>/dev/null)"

echo
echo "=== tooling ==="
line "git"              "$(have git)"
line "composer"         "$(have composer)"
line "node"             "$(have node)"
line "npm"              "$(have npm)"
line "node version"     "$(node -v 2>/dev/null || echo '-')"

echo
echo "=== media (optional, degrades gracefully) ==="
line "ffmpeg"           "$(have ffmpeg)"
line "ffprobe"          "$(have ffprobe)"
echo "  Without ffprobe, image posts work fully. Video duration and dimensions"
echo "  cannot be verified before publishing, so a Reel that breaks Meta's specs"
echo "  is found by Meta rather than by the composer."

echo
echo "=== database ==="
line "mysql client"     "$(have mysql)"

echo
echo "=== web root ==="
DOCROOT="$HOME/domains/gnextsocial.gnext.space/public_html"
line "docroot"          "$([ -d "$DOCROOT" ] && echo "$DOCROOT" || echo 'NOT FOUND')"
line "docroot contents" "$(ls -A "$DOCROOT" 2>/dev/null | tr '\n' ' ' | cut -c1-70)"
line ".htaccess allowed" "$([ -f "$DOCROOT/.htaccess" ] && echo 'one already present' || echo 'none yet')"

echo
echo "=== cron ==="
line "crontab"          "$(have crontab)"
echo "  current entries:"
crontab -l 2>/dev/null | sed 's/^/    /' || echo "    (none, or not readable)"

echo
echo "Paste this whole output back."
