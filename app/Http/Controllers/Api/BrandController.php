<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BrandController extends Controller
{
    public function index()
    {
        $storeId = Auth::user()->store_id;
        $brands = Brand::where('store_id', $storeId)->with('store')->get();

        return response()->json([
            'status' => true,
            'brands' => $brands
        ], 200);
    }

    public function show($id)
    {
        $storeId = Auth::user()->store_id;
        $brand = Brand::where('store_id', $storeId)->where('id', $id)->first();

        if (!$brand) {
            return response()->json([
                'status' => false,
                'message' => 'Brand not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'brand' => $brand
        ], 200);
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
            ]);

            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $brand = Brand::create([
                'store_id' => $storeId,
                'name' => $request->name,
                'description' => $request->description,
                'created_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Brand created successfully',
                'brand' => $brand
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while creating the brand.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
            ]);

            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $brand = Brand::where('store_id', $storeId)->where('id', $id)->first();

            if (!$brand) {
                return response()->json([
                    'status' => false,
                    'message' => 'Brand not found'
                ], 404);
            }

            $brand->update([
                'name' => $request->name,
                'description' => $request->description,
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Brand updated successfully',
                'brand' => $brand
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while updating the brand.',
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

            $brand = Brand::where('store_id', $storeId)->where('id', $id)->first();

            if (!$brand) {
                return response()->json([
                    'status' => false,
                    'message' => 'Brand not found'
                ], 404);
            }

            $brand->delete();

            return response()->json([
                'status' => true,
                'message' => 'Brand deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while deleting the brand.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
