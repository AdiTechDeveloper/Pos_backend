<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    /**
     * Get all stores
     */
    public function index()
    {
        $user = Auth::user();
        $branches = Branch::where('store_id', $user->store_id)->get();

        return response()->json([
            'status' => true,
            'data' => $branches
        ], 200);
    }

    /**
     * Get branch by ID
     */
    public function show($id)
    {
        $user = Auth::user();
        $branch = Branch::find($id);

        if (!$branch) {
            return response()->json([
                'status' => false,
                'message' => 'Branch not found'
            ], 404);
        }

        if ($branch->store_id !== $user->store_id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized - You cannot access a branch from another store'
            ], 403);
        }

        return response()->json([
            'status' => true,
            'data' => $branch
        ], 200);
    }

    /**
     * Create a new store and its admin user
     */
    public function store(Request $request)
    {
        $store_id = Auth::user()->store_id;

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('branches')->where(fn($q) => $q->where('store_id', $store_id)),
            ],
            [
                'name.unique' => 'Branch name already exists for this store.',
            ],
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
        ]);

        $branch = Branch::create([
            'store_id' => $store_id,
            'name' => $validated['name'],
            'address' => $validated['address'] ?? null,
            'phone' => $validated['phone'] ?? null,
        ]);

        return response()->json([
            'message' => 'Branch created successfully - Store ' . $branch->store->name,
            'data' => $branch
        ], 201);
    }

    /**
     * Update branch by ID
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $branch = Branch::find($id);

        if (!$branch) {
            return response()->json([
                'status' => false,
                'message' => 'Branch not found'
            ], 404);
        }

        if ($user->store_id !== $branch->store_id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized - You cannot update a branch of another store'
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'address' => 'sometimes|string|max:500',
            'phone' => 'sometimes|string|max:20',
        ]);

        $branch->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Branch updated successfully - Store ' . $branch->store->name,
            'data' => $branch
        ], 200);
    }

    /**
     * Delete branch by ID
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $branch = Branch::find($id);

        if (!$branch) {
            return response()->json([
                'status' => false,
                'message' => 'Branch not found'
            ], 404);
        }

        if ($branch->store_id !== $user->store_id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized - You cannot delete a branch from another store'
            ], 403);
        }

        $branch->delete();

        return response()->json([
            'status' => true,
            'message' => 'Branch deleted successfully - Store ' . $user->store->name
        ], 200);
    }
}
