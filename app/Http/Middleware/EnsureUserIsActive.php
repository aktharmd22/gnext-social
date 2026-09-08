<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivated users are logged out on their next request.
 *
 * Users are deactivated rather than deleted -- they still authored posts and
 * appear throughout the activity log -- so the account row survives and this is
 * what actually stops them getting in.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => 'This account has been deactivated. Ask an admin to restore it.',
                ]);
        }

        return $next($request);
    }
}
