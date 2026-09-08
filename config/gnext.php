<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| GnextSocial
|------------------------------------------------------------------------------
| Deployment facts only. Anything an admin should be able to change without a
| deploy -- Meta app id, app secret, Graph version, redirect URI, notification
| routing -- lives in the database, not here.
*/

return [

    /*
    | Timestamps are stored UTC everywhere. This is the zone they are rendered
    | in when a user has not set their own.
    */
    'default_timezone' => env('GNEXT_DEFAULT_TIMEZONE', 'Asia/Dubai'),

    'meta' => [
        /*
        | Where the Graph API lives.
        |
        | Configurable so the whole publish path can be exercised against a
        | stand-in during a staging run or an end-to-end rehearsal. Defaults to
        | the real thing; only ever point it elsewhere deliberately.
        */
        'base_url' => env('GNEXT_GRAPH_BASE_URL', 'https://graph.facebook.com'),

        /*
        | Binds every Graph call to this app, so a leaked token alone cannot be
        | replayed from elsewhere. Meta ignores it when the app does not require
        | it, so sending it always costs nothing.
        */
        'send_appsecret_proof' => env('GNEXT_APPSECRET_PROOF', true),

        /*
        | Permissions the OAuth flow asks for. Publishing to Instagram needs all
        | of these plus Business Verification and App Review.
        */
        'scopes' => [
            'pages_show_list',
            'pages_read_engagement',
            'pages_manage_posts',
            'pages_manage_engagement',
            'instagram_basic',
            'instagram_content_publish',
            'business_management',
        ],
    ],

    'media' => [
        /*
        | Meta fetches media from us by URL, so ingested files must land on a
        | disk that is reachable from the public internet.
        */
        'disk' => env('GNEXT_MEDIA_DISK', 'public'),

        'max_bytes' => (int) env('GNEXT_MEDIA_MAX_BYTES', 500 * 1024 * 1024),

        'ffmpeg' => env('GNEXT_FFMPEG_PATH', 'ffmpeg'),
        'ffprobe' => env('GNEXT_FFPROBE_PATH', 'ffprobe'),

        'thumbnail_width' => 480,

        /*
        | A Google Drive share link is an HTML page, not an image. Meta cannot
        | follow it, so ingestion rewrites it to the direct-download form and
        | pulls the bytes server-side.
        */
        'drive_download_template' => 'https://drive.google.com/uc?export=download&id=%s',
    ],

    'publishing' => [
        /*
        | On boot, pick up anything due within this window that never ran, and
        | publish it late rather than dropping it silently.
        */
        'recovery_window_hours' => (int) env('GNEXT_RECOVERY_WINDOW_HOURS', 6),

        /*
        | Instagram container processing for a Reel can take 30+ seconds. Poll
        | with backoff, give up after this many seconds, then surface the real
        | error rather than a timeout.
        */
        'container_poll_timeout' => (int) env('GNEXT_IG_CONTAINER_POLL_TIMEOUT', 300),
        'container_poll_initial_delay' => 3,
        'container_poll_max_delay' => 20,

        /*
        | Retry schedule in seconds: 1m, 5m, 15m. Applied only to errors that
        | are actually retryable -- a terminal error never burns an attempt.
        */
        'retry_backoff' => [60, 300, 900],
        'max_attempts' => 3,
    ],

    'tokens' => [
        /*
        | Warn the admin this many days before a Page token expires, by email
        | and with a persistent dashboard banner.
        */
        'warn_days' => [14, 7],
    ],

    'composer' => [
        /*
        | Warn if a caption is this similar to anything published recently.
        */
        'duplicate_similarity' => 80,
        'duplicate_lookback_days' => 60,

        /*
        | A run of this many consecutive empty days inside the visible month
        | renders a gap marker with a "Fill this gap" action.
        */
        'gap_threshold_days' => 3,
    ],

    'review' => [
        /*
        | Shareable client review links expire, and are rate limited.
        */
        'link_ttl_days' => 14,
        'rate_limit_per_minute' => 10,
    ],

];
