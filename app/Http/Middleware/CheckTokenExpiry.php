<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTokenExpiry
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if token is missing
        if (!$request->bearerToken()) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $user = $request->user();

        // Invalid token or token not linked to a user
        if (!$user) {
            return response()->json(['message' => 'Invalid or expired token'], 401);
        }

        $token = $user->currentAccessToken();

        // Expired token check
        if ($token && $token->expires_at && $token->expires_at->isPast()) {
            $token->delete(); // revoke expired token
            return response()->json(['message' => 'Token expired. Please login again.'], 401);
        }

        return $next($request);
    }
}
