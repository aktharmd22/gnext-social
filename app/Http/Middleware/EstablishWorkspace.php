<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the request to the authenticated user's workspace.
 *
 * Every authenticated route passes through this, which is what makes the
 * WorkspaceScope global scope meaningful: without it the scope would no-op and
 * queries would span tenants.
 */
class EstablishWorkspace
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $this->context->set($user->workspace_id);

            // Eagerly, because lazy loading is an error in this application and
            // the shell reads the workspace on every request (timezone, name).
            $user->loadMissing('workspace');
        }

        return $next($request);
    }
}
