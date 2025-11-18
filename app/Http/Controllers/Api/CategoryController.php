<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{
    public function index()
    {
        $storeId = Auth::user()->store_id;
        $categories = Category::where('store_id', $storeId)->get();

        return response()->json([
            'status' => true,
            'categories' => $categories
        ], 200);
    }

    public function show($id)
    {
        $storeId = Auth::user()->store_id;
        $category = Category::where('store_id', $storeId)->where('id', $id)->first();

        if (!$category) {
            return response()->json([
                'status' => false,
                'message' => 'Category not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'category' => $category
        ], 200);
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'parent_id' => 'nullable|exists:categories,id',
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

            $category = Category::create([
                'store_id' => $storeId,
                'name' => $request->name,
                'parent_id' => $request->parent_id,
                'description' => $request->description,
                'created_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'category' => $category
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while creating the category.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'parent_id' => 'nullable|exists:categories,id',
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

            $category = Category::where('store_id', $storeId)->where('id', $id)->first();

            if (!$category) {
                return response()->json([
                    'status' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            $category->update([
                'name' => $request->name,
                'parent_id' => $request->parent_id,
                'description' => $request->description,
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Category updated successfully',
                'category' => $category
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while updating the category.',
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

            $category = Category::where('store_id', $storeId)->where('id', $id)->first();

            if (!$category) {
                return response()->json([
                    'status' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            $category->delete();

            return response()->json([
                'status' => true,
                'message' => 'Category deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while deleting the category.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function parentCategories()
    {
        $storeId = Auth::user()->store_id;

        $parentCategories = Category::where('store_id', $storeId)
            ->whereNull('parent_id')
            ->get();

        return response()->json([
            'status' => true,
            'parent_categories' => $parentCategories
        ], 200);
    }

    public function subCategories($parentId)
    {
        $storeId = Auth::user()->store_id;

        $subCategories = Category::where('store_id', $storeId)
            ->where('parent_id', $parentId)
            ->get();

        return response()->json([
            'status' => true,
            'sub_categories' => $subCategories
        ], 200);
    }

    public function categoryTree()
    {
        $storeId = Auth::user()->store_id;

        $categories = Category::where('store_id', $storeId)
            ->whereNull('parent_id')
            ->with('childrenRecursive')
            ->get();

        return response()->json([
            'status' => true,
            'category_tree' => $categories
        ], 200);
    }
}
