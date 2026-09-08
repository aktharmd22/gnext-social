<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level admin gate.
 *
 * Policies still guard every individual action. This exists so that an entire
 * admin section cannot be reached by typing its URL -- hiding the nav link is
 * not access control.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin() === true, 403);

        return $next($request);
    }
}
