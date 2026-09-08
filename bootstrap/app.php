<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EstablishWorkspace;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // No `health:` entry. Laravel's stock /up answers 200 whenever PHP is
        // alive, which would show green while every token is expired and
        // nothing has published for a day. HealthController answers the
        // question that actually matters: can this installation publish?
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Every web request establishes its workspace and re-checks that the
         * account is still active. Appended to the group rather than applied
         * per-route so that a new route cannot accidentally omit them.
         */
        $middleware->web(append: [
            EstablishWorkspace::class,
            EnsureUserIsActive::class,
        ]);

        $middleware->alias([
            'admin' => EnsureAdmin::class,
        ]);

        /*
         * Proxy trust, for running behind Cloudflare or any other CDN.
         *
         * Three things break without it when the proxy terminates TLS:
         *
         *  - Generated URLs come out http://, and a signed client review link
         *    generated as http but visited as https fails its own signature
         *    check. The reviewer gets 403 on a link that is perfectly valid.
         *  - Every request appears to come from the proxy, so the login
         *    throttle counts all users in one bucket: one person fumbling a
         *    password locks out the whole team.
         *  - The activity log records the proxy's address on every row, which
         *    makes the audit trail worthless.
         *
         * Left empty by default, and that is deliberate rather than lazy. This
         * origin is reachable by its own IP, so trusting forwarded headers from
         * anyone would let a direct caller claim any address they like and walk
         * past the rate limiter. Only set TRUSTED_PROXIES once traffic actually
         * arrives through a proxy, and prefer that proxy's published ranges to
         * a blanket '*'.
         */
        $proxies = trim((string) env('TRUSTED_PROXIES', ''));

        if ($proxies !== '') {
            $middleware->trustProxies(
                at: $proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))),
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO
                    | Request::HEADER_X_FORWARDED_AWS_ELB
            );
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * A stale CSRF token is not an error worth a red page.
         *
         * It happens for an ordinary reason -- a form left open while the
         * session lapsed, or a deploy that cleared sessions -- and the useful
         * response is to put the person back where they were with an
         * explanation, not to show them "419 PAGE EXPIRED" and leave them to
         * guess.
         */
        /*
         * Matched on the converted HttpException, not on TokenMismatchException
         * itself: Handler::render() calls prepareException() -- which turns a
         * TokenMismatchException into HttpException(419) -- BEFORE it consults
         * render callbacks, so a callback type-hinted on the original never
         * fires. Returning null lets every other status fall through.
         */
        $exceptions->render(function (HttpException $exception, Request $request) {
            if ($exception->getStatusCode() !== 419) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your session expired. Reload the page and try again.',
                ], 419);
            }

            $target = $request->user() !== null
                ? url()->previous()
                : route('login');

            return redirect()->to($target)->with(
                'error',
                'Your session expired before that was submitted, so nothing was saved. Please try again.'
            );
        });
    })->create();
