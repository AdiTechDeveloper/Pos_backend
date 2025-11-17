<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class ManagerBranchController extends Controller
{
    public function myBranches()
    {
        $user = Auth::user();

        if ($user->role !== 'manager') {
            return response()->json(['message' => 'Unauthorized - Manager only'], 403);
        }

        $branches = $user->branches()->get();

        return response()->json([
            'status' => true,
            'branches' => $branches
        ], 200);
    }

    public function branchStaff($branchId)
    {
        try {
            $user = Auth::user();

            if ($user->role !== 'manager') {
                return response()->json(['message' => 'Unauthorized - Manager only'], 403);
            }

            $hasAccess = $user->branches()->where('branch_id', $branchId)->exists();

            if (!$hasAccess) {
                return response()->json(['message' => 'Unauthorized - No access to this branch'], 403);
            }

            $staff = User::whereHas('branches', function ($query) use ($branchId) {
                $query->where('branch_id', $branchId)->where('role', 'cashier');
            })->get();

            return response()->json([
                'status' => true,
                'staff' => $staff
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Invalid branch ID', $e->getMessage()], 400);
        }
    }

    public function staffDetail($staffId)
    {
        try {
            $user = Auth::user();

            if ($user->role !== 'manager') {
                return response()->json(['message' => 'Unauthorized - Manager only'], 403);
            }

            $staff = User::where('id', $staffId)->where('role', 'cashier')->first();

            if (!$staff) {
                return response()->json([
                    'status' => false,
                    'message' => 'Staff not found'
                ], 404);
            }

            $managerBranchIds = $user->branches()->pluck('branch_id');

            $hasAccess = User::where('id', $staffId)
                ->whereHas('branches', function ($query) use ($managerBranchIds) {
                    $query->whereIn('branch_id', $managerBranchIds);
                })
                ->exists();

            if (!$hasAccess) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized access'
                ], 403);
            }

            $staff->load('branches');

            return response()->json([
                'status' => true,
                'staff' => $staff
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Invalid staff ID', $e->getMessage()], 400);
        }
    }
}
