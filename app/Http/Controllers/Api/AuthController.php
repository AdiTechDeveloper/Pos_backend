<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\RegisterShift;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required_without:pin|string',
            'password' => 'required_without:pin|string',
            'pin' => 'required_without_all:username,password|string',
        ]);

        // Cashier login (PIN based)
        if ($request->has('pin')) {
            $user = User::where('role', 'cashier')->where('is_active', true)->first();

            if (! $user || ! Hash::check($request->pin, $user->pin_hash)) {
                return response()->json(['message' => 'Invalid cashier PIN'], 401);
            }
        }
        // Email + password login
        else {
            $user = User::where('username', $request->username)
                ->where('is_active', true)
                ->first();

            if (! $user || ! Hash::check($request->password, $user->password)) {
                return response()->json(['message' => 'Invalid credentials'], 401);
            }
        }

        $expiry = Carbon::now()->addHours(8);

        $plainTextToken = $user->createToken('auth_token', ['*'])->plainTextToken;
        $user->tokens()->latest()->first()->update([
            'expires_at' => $expiry,
        ]);

        return response()->json([
            'message' => 'Login successful',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'role' => $user->role,
                'store_id' => $user->store_id,
                'branch_ids' => $user->branches()->pluck('branches.id'),
                'is_active' => $user->is_active,
            ],
            'token' => $plainTextToken,
            'expires_at' => $expiry->toDateTimeString(),
        ]);
    }

    

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }
}
