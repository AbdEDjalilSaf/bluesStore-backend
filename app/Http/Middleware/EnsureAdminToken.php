<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminToken
{
    /**
     * Allow the request through only when X-Admin-Token matches the
     * configured admin token. Fails closed when no token is configured.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('shop.admin_token');
        $provided = (string) $request->header('X-Admin-Token', '');

        if (! is_string($expected) || $expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
