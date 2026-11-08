<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()->is_active) {
            $request->user()->tokens()->delete();

            return response()->json([
                'message' => 'This account is inactive. Contact support for assistance.',
                'code' => 'account_inactive',
            ], 403);
        }

        return $next($request);
    }
}
