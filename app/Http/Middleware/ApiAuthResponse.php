<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;

class ApiAuthResponse
{
    public function handle($request, Closure $next)
    {
        try {
            return $next($request);
        } catch (AuthenticationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated or expired token.'
            ], 401);
        }
    }
}
    