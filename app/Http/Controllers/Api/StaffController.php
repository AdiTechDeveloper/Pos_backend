<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        try {
            if (! in_array($request->user()->role, ['admin', 'manager'])) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $staff = User::where('store_id', $request->user()->store_id)
                ->whereIn('role', ['manager', 'cashier'])
                ->with('store','branches')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $staff
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while fetching staff',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $authUser = Auth::user();
        $staff = User::with('branches')->find($id);

        if (!$staff) {
            return response()->json([
                'status' => false,
                'message' => 'Staff not found'
            ], 404);
        }

        if (in_array($staff->role, ['superadmin'])) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot view superadmin data'
            ], 403);
        }

        if ($authUser->role === 'admin') {
            if ($authUser->store_id !== $staff->store_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'You are not allowed to view staff from another store'
                ], 403);
            }
        }

        return response()->json([
            'status' => true,
            'data' => $staff
        ], 200);
    }

    public function store(Request $request)
    {
        try {
            if (! in_array($request->user()->role, ['admin', 'manager'])) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'username' => 'required|string|max:255|unique:users,username',
                'role' => 'required|in:manager,cashier',
                'pin' => 'nullable|required_if:role,cashier|digits:4',
                'branch_ids' => 'required|array|min:1',
                'branch_ids.*' => 'exists:branches,id'
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
            }

            $data = $validator->validated();
            $sPassword = '123456';

            $staff = User::create([
                'store_id' => $request->user()->store_id,
                'name' => $data['name'],
                'username' => $data['username'],
                'role' => $data['role'],
                'password' => $data['role'] !== 'cashier' ? Hash::make($sPassword) : null,
                'pin_hash' => $data['role'] === 'cashier' ? Hash::make($data['pin']) : null,
                'created_by' => $request->user()->id
            ]);

            $staff->branches()->sync($data['branch_ids']);

            return response()->json([
                'status' => true,
                'message' => 'Staff created',
                'data' => $staff->load('branches')
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while creating staff',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $authUser = Auth::user();

            if (! in_array($authUser->role, ['admin', 'manager'])) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $staff = User::find($id);

            if (! $staff) {
                return response()->json([
                    'status' => false,
                    'message' => 'Staff not found'
                ], 404);
            }

            if ($authUser->role === 'admin' && $authUser->store_id !== $staff->store_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'You are not allowed to update staff from another store'
                ], 403);
            }

            if (in_array($staff->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot update admin or superadmin data'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'role' => 'required|in:manager,cashier',
                'pin' => 'nullable|required_if:role,cashier|digits:4',
                'branch_ids' => 'required|array|min:1',
                'branch_ids.*' => 'exists:branches,id'
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
            }

            $data = $validator->validated();

            $staff->role = $data['role'];
            $staff->updated_by = $authUser->id;

            if ($data['role'] === 'cashier' && isset($data['pin'])) {
                $staff->pin_hash = Hash::make($data['pin']);
                $staff->password = null;
            } elseif ($data['role'] !== 'cashier') {
                $staff->pin_hash = null;
                if (! $staff->password) {
                    $sPassword = '123456';
                    $staff->password = Hash::make($sPassword);
                }
            }

            $staff->save();

            $staff->branches()->sync($data['branch_ids']);

            return response()->json([
                'status' => true,
                'message' => 'Staff updated',
                'data' => $staff->load('branches')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while updating staff',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $authUser = Auth::user();

            if (! in_array($authUser->role, ['admin', 'manager'])) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $staff = User::find($id);

            if (! $staff) {
                return response()->json([
                    'status' => false,
                    'message' => 'Staff not found'
                ], 404);
            }

            if ($authUser->role === 'admin' && $authUser->store_id !== $staff->store_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'You are not allowed to delete staff from another store'
                ], 403);
            }

            if (in_array($staff->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot delete admin or superadmin data'
                ], 403);
            }

            $staff->branches()->detach();
            $staff->delete();

            return response()->json([
                'status' => true,
                'message' => 'Staff deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while deleting staff',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function toggleActive(Request $request, $id)
    {
        try {
            $authUser = Auth::user();

            if (! in_array($request->user()->role, ['admin', 'superadmin'])) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $staff = User::find($id);

            if (! $staff) {
                return response()->json([
                    'status' => false,
                    'message' => 'Staff not found'
                ], 404);
            }

            if ($authUser->role === 'admin' && $authUser->store_id !== $staff->store_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'You are not allowed to update staff from another store'
                ], 403);
            }

            $staff->is_active = ! $staff->is_active;
            $staff->updated_by = $authUser->id;
            $staff->save();

            return response()->json([
                'status' => true,
                'message' => 'Staff ' . ($staff->is_active ? 'activated' : 'deactivated') . ' successfully',
                'data' => $staff
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while updating staff status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
