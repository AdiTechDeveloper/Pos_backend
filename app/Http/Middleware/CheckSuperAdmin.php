<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'superadmin') {
            return response()->json([
                'status' => false,
                'message' => 'Access denied. Only superadmin can perform this action.'
            ], 403);
        }

        return $next($request);
    }
}
