<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

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

    public function changePassword(Request $request)
    {
        $currentUser = Auth::user();

        if ($request->has('user_id') && $request->user_id != $currentUser->id) {

            $request->validate([
                'user_id' => 'required|exists:users,id',
            ]);

            $targetUser = User::find($request->user_id);

            if ($currentUser->role === 'cashier') {
                return response()->json([
                    'message' => 'Unauthorized. Cashiers cannot modify other staff credentials.',
                ], 403);
            }

            if ($currentUser->role === 'manager') {
                if ($targetUser->role !== 'cashier') {
                    return response()->json([
                        'message' => 'Unauthorized. Managers can only modify cashier passwords.',
                    ], 403);
                }

                $managerBranches = $currentUser->branches()->pluck('branches.id')->toArray();
                $cashierHasSameBranch = $targetUser->branches()->whereIn('branches.id', $managerBranches)->exists();

                if (! $cashierHasSameBranch) {
                    return response()->json([
                        'message' => 'Unauthorized. This cashier does not belong to your branch.',
                    ], 403);
                }
            }

            if ($targetUser->role === 'cashier') {
                $passwordRules = ['required', 'confirmed', 'regex:/^\d{4}$/'];
            } else {
                $passwordRules = ['required', 'confirmed', Password::min(6)];
            }

            $request->validate([
                'password' => $passwordRules,
            ], [
                'password.regex' => 'The cashier password must be exactly a 4-digit numeric PIN.',
            ]);

            $targetUser->update(['password' => Hash::make($request->password)]);

            return response()->json([
                'message' => "Password for {$targetUser->name} ({$targetUser->role}) updated successfully.",
            ], 200);
        }

        if ($currentUser->role === 'cashier') {
            return response()->json([
                'message' => 'Unauthorized endpoint access for this role.',
            ], 403);
        }

        $request->validate([
            'current_password' => 'required|current_password',
            'password' => ['required', 'confirmed', Password::min(6)],
        ]);

        $currentUser->update(['password' => Hash::make($request->password)]);

        return response()->json([
            'message' => 'Your password has been changed successfully.',
        ], 200);
    }

    public function emergencyAdminReset(Request $request)
    {
        $request->validate([
            'admin_username' => 'required|exists:users,username',
            'master_key' => 'required',
            'new_password' => ['required', 'confirmed', Password::min(6)],
        ]);

        if ($request->master_key !== env('MASTER_RECOVERY_KEY')) {
            return response()->json(['message' => 'Invalid recovery PIN.'], 403);
        }

        $user = User::where('username', $request->admin_username)->first();

        if (! $user) {
            return response()->json(['message' => 'Username not found.'], 404);
        }

        if (! in_array($user->role, ['admin', 'manager'])) {
            return response()->json([
                'message' => 'Access Denied. Cashiers must contact their manager.',
            ], 403);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        Log::warning('Emergency password reset used', [
            'username' => $user->username,
            'role' => $user->role,
            'ip' => request()->ip(),
        ]);

        return response()->json([
            'message' => 'Password reset successful.',
        ], 200);
    }
}
