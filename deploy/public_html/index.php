<?php

/*
|------------------------------------------------------------------------------
| Front controller for shared hosting
|------------------------------------------------------------------------------
|
| On this host only public_html is served, and its parent directories are not.
| The application therefore lives outside the web root entirely -- at
| ~/gnextsocial by default -- and only this file, the rewrite rules beside it,
| and a few asset symlinks sit inside public_html.
|
| That matters more here than on a typical app: .env holds the database
| password and APP_KEY, and APP_KEY decrypts every stored Meta access token. If
| the application root were inside the web root, a single mis-set .htaccess
| would expose all of it. Nothing below public_html can be requested at all.
|
| Point APP_ROOT somewhere else only if you cloned somewhere else.
|
*/

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
 * public_html -> gnextsocial.gnext.space -> domains -> home, so three levels up
 * is the home directory. Both candidates are checked rather than assumed: on
 * some plans the domain folder is not nested under domains/ at all.
 */
$appRoot = getenv('GNEXT_APP_ROOT') ?: '';

if ($appRoot === '') {
    $candidates = [
        // Beside public_html, inside the domain folder. That folder is not
        // served either, so this is exactly as safe as the home directory and
        // keeps everything for one site in one place.
        dirname(__DIR__).'/app',
        dirname(__DIR__).'/gnextsocial',

        // Home directory.
        dirname(__DIR__, 3).'/gnextsocial',
        dirname(__DIR__, 2).'/gnextsocial',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate.'/vendor/autoload.php')) {
            $appRoot = $candidate;
            break;
        }
    }
}

if ($appRoot === '' || ! is_file($appRoot.'/vendor/autoload.php')) {
    http_response_code(500);

    // Deliberately terse. A stack trace here would name paths on disk.
    exit('Application not installed. Run deploy/install.sh over SSH.');
}

if (file_exists($maintenance = $appRoot.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appRoot.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $appRoot.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
