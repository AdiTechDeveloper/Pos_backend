<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class StaffController extends Controller
{
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
}
