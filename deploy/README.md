# Deploying to shared hosting

Written for the Hostinger account this runs on, but nothing here is
Hostinger-specific beyond the paths.

## Why the app does not live in `public_html`

Only `public_html` is served. Everything else in the account is unreachable
over HTTP, which is exactly where the application belongs:

```
~/gnextsocial/                              the application
  .env                                      DB password + APP_KEY
  storage/                                  logs, sessions, uploaded media
  vendor/

~/domains/gnextsocial.gnext.space/public_html/
  index.php                                 front controller, points at ~/gnextsocial
  .htaccess                                 rewrite + HTTPS redirect
  build   -> ~/gnextsocial/public/build     symlink
  fonts   -> ~/gnextsocial/public/fonts     symlink
  storage -> ~/gnextsocial/storage/app/public
```

`APP_KEY` decrypts every stored Meta access token. If the application root sat
inside the web root, one mis-set `.htaccess` would expose `.env` and with it
every connected Page. Outside the web root, that request cannot be made at all.

## First install

```bash
# 1. Look at what the host actually offers.
cd ~
git clone https://github.com/aktharmd22/gnext-social.git gnextsocial
bash ~/gnextsocial/deploy/preflight.sh

# 2. Install. It stops after creating .env so you can fill it in.
bash ~/gnextsocial/deploy/install.sh

# 3. Database credentials and APP_URL.
nano ~/gnextsocial/.env

# 4. Run it again to finish.
bash ~/gnextsocial/deploy/install.sh
```

If PHP on the command line is older than the version the site runs, pass the
right binary — the two are frequently different on shared hosting:

```bash
PHP=/usr/bin/php8.3 bash ~/gnextsocial/deploy/install.sh
```

## Cron

Two lines, added with `crontab -e`. Without them nothing publishes.

```cron
* * * * * cd ~/gnextsocial && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd ~/gnextsocial && php artisan queue:work --stop-when-empty --max-time=50 --tries=3 >> storage/logs/queue.log 2>&1
```

The second is a burst worker rather than `queue:work` as a daemon. Shared hosts
kill long-running processes, and a daemon that is killed mid-publish leaves a
post in `publishing` with no worker coming back for it. A worker that exits on
its own every minute cannot get into that state.

## Later deploys

```bash
bash ~/gnextsocial/deploy/update.sh
```

Maintenance mode, pull, `composer install`, migrate, rebuild caches,
`queue:restart`, back up. `queue:restart` matters: workers hold the old code in
memory, so without it the next publish runs against the code from before the
pull.

## Front-end assets

`public/build` is gitignored, so the server needs one of:

- **Node on the server** — `install.sh` runs `npm run build` itself.
- **No Node** — build locally and commit the output:

  ```bash
  npm run build
  git add -f public/build
  git commit -m "build assets for deploy"
  git push
  ```

  Then `git pull` on the server ships them. Repeat on any release that changes
  `resources/`.

## Finishing setup in the browser

1. Sign in at `https://gnextsocial.gnext.space`.
2. **Settings → Meta app** — App ID and secret. In the Meta dashboard, the
   redirect URI to whitelist is
   `https://gnextsocial.gnext.space/oauth/facebook/callback`.
3. **Settings → Accounts** — connect the Page and its Instagram Business
   account.
4. `php artisan gnext:preflight` should then report no problems.

The secret is write-only in the UI for every role: it is encrypted at rest,
excluded from serialisation, and never rendered back. Replacing it is possible;
reading it is not.

## Things that will bite

**`ffprobe` is usually absent on shared hosting.** The app expects this and
degrades deliberately: images publish normally, but video duration and
dimensions cannot be checked before publishing, so a Reel outside Meta's specs
is rejected by Meta rather than caught in the composer. `gnext:preflight`
reports which situation you are in.

**Cron granularity.** Posts dispatch on the minute the scheduler runs, so
publish times are accurate to about a minute, not to the second.

**`proc_open` disabled.** Some plans disable it. Composer needs it to install,
and `ffprobe` needs it to run. `preflight.sh` reports this; if it is disabled,
enable it in hPanel → PHP Configuration.

**Sessions.** `SESSION_DRIVER=database`, so the `sessions` table must exist.
Migrations create it. Clearing that table signs everyone out — expected, and
the app handles it by redirecting to login rather than showing a 419 page.

**Timezone.** Everything is stored UTC. `GNEXT_DEFAULT_TIMEZONE` only controls
what an unconfigured user sees. Do not set `APP_TIMEZONE` to `Asia/Dubai`;
storage would drift from what every existing row means.
