<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class StoreController extends Controller
{
    /**
     * Get all stores
     */
    public function index()
    {
        $stores = Store::all();
        return response()->json([
            'status' => true,
            'data' => $stores
        ], 200);
    }

    /**
     * Get store by ID
     */
    public function show($id)
    {
        $user = Auth::user();
        $store = Store::with('users')->find($id);

        if (!$store) {
            return response()->json([
                'status' => false,
                'message' => 'Store not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $store
        ], 200);
    }

    /**
     * Create a new store and its admin user
     */
    public function store(Request $request)
    {

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:stores,name',
            'address' => 'nullable|string|max:500',
            'state' => 'required|string|max:20',
            'phone' => 'nullable|string|max:20',
            'contact_person_name' => 'required|string|max:255',
            'gstin' => 'required|string|max:20',
            'tagline' => 'nullable|string|max:255',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,svg',
        ]);

        try {
            DB::beginTransaction();

            // Upload logo
            $logoPath = null;
            if ($request->hasFile('logo')) {
                $logoPath = $request->file('logo')->store('store_logos', 'public');
            }

            $storeCode = strtoupper(Str::slug(substr($validated['name'], 0, 5))) . rand(100, 999);

            $store = Store::create([
                'name' => $validated['name'],
                'code' => $storeCode,
                'address' => $validated['address'] ?? null,
                'state' => $validated['state'],
                'phone' => $validated['phone'] ?? null,
                'contact_person_name' => $validated['contact_person_name'],
                'gstin' => $validated['gstin'],
                'tagline' => $validated['tagline'] ?? null,
                'logo' => $logoPath,
            ]);
            // dd($store);

            $adminUsername = strtolower($storeCode . '_admin');
            $adminPassword = '123456';

            $admin = User::create([
                'store_id' => $store->id,
                'name' => $validated['name'] . ' Admin',
                'username' => $adminUsername,
                'password' => Hash::make($adminPassword),
                'role' => 'admin',
                'is_active' => true,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Store and admin user created successfully.',
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'code' => $store->code,
                    'address' => $store->address,
                    'state' => $store->state,
                    'phone' => $store->phone,
                    'contact_person_name' => $store->contact_person_name,
                    'gstin' => $store->gstin,
                    'tagline' => $store->tagline,
                    'logo_url' => $store->logo ? asset('storage/' . $store->logo) : null,
                ],
                'admin_user' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'username' => $admin->username,
                    'role' => $admin->role,
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the store.', $e->getMessage()], 500);
        }
    }

    /**
     * Update store (Superadmin only)
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $store = Store::find($id);

        if (!$store) {
            return response()->json([
                'status' => false,
                'message' => 'Store not found'
            ], 404);
        }

        if ($user->role === 'admin') {
            if ($user->store_id !== $store->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: You can only update your own store.'
                ], 403);
            }
        } else if ($user->role !== 'superadmin') {
            return response()->json([
                'status' => false,
                'message' => 'Access denied: Only admin or superadmin can update store info.'
            ], 403);
        }

        $validated = $request->validate([
            'address' => 'nullable|string|max:500',
            'state' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:15',
            'contact_person_name' => 'nullable|string|max:255',
            'gstin' => 'nullable|string|max:20',
            'tagline' => 'nullable|string|max:255',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,svg',
        ]);

        try {
            DB::beginTransaction();

            // Handle Logo Upload
            if ($request->hasFile('logo')) {

                // Delete old logo if exists
                if ($store->logo && Storage::disk('public')->exists($store->logo)) {
                    Storage::disk('public')->delete($store->logo);
                }

                // Upload new logo
                $validated['logo'] = $request->file('logo')->store('store_logos', 'public');
            }

            // Update store record
            $store->update($validated);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Store updated successfully!',
                'data' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'code' => $store->code,
                    'address' => $store->address,
                    'state' => $store->state,
                    'phone' => $store->phone,
                    'contact_person_name' => $store->contact_person_name,
                    'gstin' => $store->gstin,
                    'tagline' => $store->tagline,
                    'logo_url' => $store->logo ? asset('storage/' . $store->logo) : null,
                ]
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Error updating store.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete store
     */
    public function destroy($id)
    {
        $store = Store::find($id);

        if (!$store) {
            return response()->json([
                'status' => false,
                'message' => 'Store not found'
            ], 404);
        }

        $adminUser = User::where('store_id', $store->id)->where('role', 'admin')->first();

        if ($adminUser) {
            $adminUser->delete();
        }

        User::where('store_id', $store->id)->where('role', '!=', 'admin')->update(['store_id' => null]);

        if ($store->logo && Storage::disk('public')->exists($store->logo)) {
            Storage::disk('public')->delete($store->logo);
        }

        $store->delete();

        return response()->json([
            'status' => true,
            'message' => 'Store deleted successfully. Related admin user removed and other users detached from the store.'
        ], 200);
    }
}
