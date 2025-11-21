<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SupplierController extends Controller
{
    public function index()
    {
        $storeId = Auth::user()->store_id;
        $suppliers = Supplier::where('store_id', $storeId)->get();

        return response()->json([
            'status' => true,
            'suppliers' => $suppliers
        ], 200);
    }

    public function show($id)
    {
        $storeId = Auth::user()->store_id;
        $supplier = Supplier::where('store_id', $storeId)->where('id', $id)->first();

        if (!$supplier) {
            return response()->json([
                'status' => false,
                'message' => 'Supplier not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'supplier' => $supplier
        ], 200);
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'gstin' => 'required|string|max:15',
                'contact' => 'required|string|max:20',
                'address' => 'required|string',
                'state' => 'required|string|max:20'
            ]);

            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $supplier = Supplier::create([
                'store_id' => $storeId,
                'name' => $request->name,
                'gstin' => $request->gstin,
                'contact' => $request->contact,
                'address' => $request->address,
                'state' => $request->state,
                'created_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Supplier created successfully',
                'supplier' => $supplier
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while creating the supplier.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'gstin' => 'required|string|max:15',
                'contact' => 'required|string|max:20',
                'address' => 'required|string',
                'state' => 'required|string|max:20',
            ]);

            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $supplier = Supplier::where('store_id', $storeId)->where('id', $id)->first();

            if (!$supplier) {
                return response()->json([
                    'status' => false,
                    'message' => 'Supplier not found'
                ], 404);
            }

            $supplier->update([
                'name' => $request->name,
                'gstin' => $request->gstin,
                'contact' => $request->contact,
                'address' => $request->address,
                'state' => $request->state,
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Supplier updated successfully',
                'supplier' => $supplier
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while updating the supplier.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $supplier = Supplier::where('store_id', $storeId)->where('id', $id)->first();

            if (!$supplier) {
                return response()->json([
                    'status' => false,
                    'message' => 'Supplier not found'
                ], 404);
            }

            $supplier->delete();

            return response()->json([
                'status' => true,
                'message' => 'Supplier deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while deleting the supplier.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
