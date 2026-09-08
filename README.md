# GnextSocial

Self-hosted social media scheduling and publishing for Facebook Pages and
Instagram Business accounts.

Replaces a spreadsheet that tracked date, day, content type, status, a Drive
link and a caption — and adds the three things a spreadsheet cannot do:
validation, publishing, and an audit trail.

---

## What it does

- **Calendar** — month, week, list and status-board views. Drag to reschedule,
  gap markers on runs of empty days, and a UAE observance overlay (Ramadan, both
  Eids, National Day, back-to-school, peak summer).
- **Composer** — live Instagram and Facebook previews with real truncation
  points, character/hashtag/mention counters, per-platform caption splitting,
  Arabic captions with RTL, and a duplicate-content warning.
- **Media pipeline** — accepts uploads, Google Drive links or any public URL,
  fetches the bytes server-side, probes the real format, generates thumbnails,
  and validates against platform rules *before* scheduling.
- **Publisher** — Facebook in one call; Instagram via container → poll →
  publish, including carousels and Reels. Per-destination retries that
  distinguish retryable from terminal failures.
- **Import/export** — a four-step wizard (upload → map → dry run → commit) with
  a per-row verdict and a downloadable error report, plus XLSX/CSV export and a
  PDF content calendar.
- **Approval** — an internal queue plus signed, expiring client review links
  that need no account.

---

## Requirements

| | Minimum | This build was developed against |
|---|---|---|
| PHP | 8.2 | 8.2.12 |
| Database | MySQL 8 or MariaDB 10.4+ | MariaDB 10.4.32 |
| Node | 20+ | 24.4.0 |
| FFmpeg | any recent | 8.1.1 (`ffmpeg` + `ffprobe` on `PATH`) |

PHP extensions: `gd`, `intl`, `zip`, `fileinfo`, `curl`, `openssl`, `pdo_mysql`.

`upload_max_filesize` and `post_max_size` should be at least as large as the
biggest video you intend to upload directly (the media cap is 500MB by default,
set by `GNEXT_MEDIA_MAX_BYTES`). Drive and URL ingestion happen server-side and
are not limited by those directives.

---

## Setup

```bash
composer install
npm install && npm run build

cp .env.example .env
php artisan key:generate

# Create the database first, with utf8mb4_unicode_ci.
php artisan migrate --seed
php artisan storage:link
```

The seed creates one workspace, two accounts and twelve sample posts:

```
admin@gnextsocial.test / password    admin
writer@gnextsocial.test / password   user
```

Delete the samples once you have your own — or run
`php artisan migrate:fresh` without `--seed` for an empty install.

---

## Running it

### The web app

```bash
php artisan serve
```

### The worker — required

Publishing never happens in a web request. Nothing goes out without a worker.

```bash
php artisan queue:work --tries=1
```

`--tries=1` is deliberate: retries are owned by the publisher, which knows the
difference between an expired token and a rate limit. The queue would retry both
identically.

### The scheduler — required

One cron entry drives everything time-based:

```cron
* * * * * cd /path/to/gnextsocial && php artisan schedule:run >> /dev/null 2>&1
```

On Windows, a Task Scheduler entry running the same command every minute.

That single entry runs:

| Command | When | Why |
|---|---|---|
| `gnext:dispatch-due` | every minute | Instagram has no scheduling API, so this application owns the clock |
| `gnext:recover-missed` | hourly | publishes anything that fell due while the worker was down, flagged late |
| `gnext:check-tokens` | daily 07:00 | Page tokens expire; warns at 14 and 7 days |
| `gnext:capture-insights` | hourly | metrics at +24h, +7d, +30d |
| `gnext:weekly-digest` | Sundays 08:00 | what published, what is scheduled, what is empty |

Each accepts `--dry` or equivalent so you can see what it *would* do.

### Health

`GET /up` answers the question that matters — *can this installation publish?* —
rather than merely whether PHP is alive. It reports queue depth, failed jobs,
token expiry, stuck media and overdue posts, and returns **503** when publishing
has actually stopped. Point your uptime monitor at it.

---

## Connecting Meta

1. **Settings → Meta app.** Enter your App ID and secret, pick a Graph version
   (v21.0 or later), and copy the OAuth redirect URI shown there into your Meta
   app under *Facebook Login → Settings → Valid OAuth Redirect URIs*. It must
   match exactly, including the scheme.
2. **Test connection.** Calls `debug_token` and reports what it finds in plain
   language, including which scopes are still missing.
3. **Settings → Connected accounts → Connect Facebook.** Choose which Pages this
   workspace publishes to. Any linked Instagram Business account comes with its
   Page automatically.

Instagram publishing additionally requires:

- the Instagram account to be **Business or Creator**, not personal;
- it to be **linked to the Facebook Page** you connect;
- scopes `instagram_basic`, `instagram_content_publish`, `pages_show_list`,
  `pages_read_engagement`, `pages_manage_posts`;
- **Meta App Review and Business Verification** before it works outside
  development mode.

While your app is in development mode you can publish to Pages you administer.
That is enough to verify the whole path end to end before review completes.

### Check it before you trust it

```bash
php artisan gnext:preflight
```

Checks everything that must be true before a post can publish — ffprobe, GD, a
publicly reachable media disk, the queue, the app credentials against Meta's own
`debug_token`, every connected token's expiry, whether each Instagram account has
its linked Page, and whether any media failed to fetch. It names what is missing
and what to do about it, and exits non-zero if publishing would fail. Worth
running after any deploy.

The publish path itself has been rehearsed end to end in real processes — cron
command, queue worker, HTTP, retries and all — against a stand-in Graph API.
`GNEXT_GRAPH_BASE_URL` is what points it somewhere other than Meta; preflight
warns loudly if it is ever left set.

---

## Production notes

### Queues

The queue driver is env-switched. `database` works anywhere and is the default.
For production on Linux, switch to Redis and run Horizon:

```dotenv
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
```

Horizon's config ships with the project. It does **not** run on Windows —
Horizon requires `pcntl`. Every job is written driver-agnostic, so this is a
one-line change rather than a port.

### Secrets

The Meta app secret, every access token and the Telegram bot token are encrypted
at rest with Laravel's `encrypted` cast, hidden from serialisation, and
write-only in the UI. No role can read a stored secret back — an admin included.
`publish_logs` records every Graph request with tokens redacted before the row
is written.

Rotating `APP_KEY` makes every stored secret unreadable. Reconnect the accounts
afterwards.

### Permissions

Two roles. Both may configure the API — a deliberate change from the original
brief — while user management, approvals, force-publish, the activity log and
workspace settings remain admin-only. The full matrix is expressed as gates and
policies in `app/Providers/AuthServiceProvider.php` and
`app/Policies/PostPolicy.php`, and is covered by `AuthorizationTest`.

---

## Testing

```bash
php artisan test
```

303 tests. They run against **MariaDB**, not SQLite: the production engine has
different JSON handling, index prefix limits and collation behaviour, and
testing on SQLite would hide exactly the failures worth catching. Create an
empty `gnextsocial_test` database first; `phpunit.xml` points at it.

The entire Meta integration is tested against a faked Graph API, so the publish
path — including Instagram's three-step dance, its polling, and every error
classification — is verified without credentials.

---

## Architecture notes

Three decisions worth knowing before changing anything:

**`post_targets` is the source of truth.** One row per post per destination.
`posts.status` is a *summary* derived from those rows by `PostStatusDeriver`,
which is why `partially_published` exists: one caption can succeed on Facebook
and fail on Instagram, and a single status column cannot express that.

**Media is never handed to Meta as a third-party URL.** Meta fetches media
itself, server-side, with no browser and no Google session. A Drive share link
returns an HTML page, not an image. `MediaIngestor` resolves, downloads, verifies
the bytes are not HTML, stores locally and gives Meta a URL we control.

**Everything is stored UTC and displayed in the viewer's zone, always
labelled.** A bare time with no zone is a bug.
