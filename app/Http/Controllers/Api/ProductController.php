<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Milon\Barcode\Facades\DNS1DFacade as DNS1D;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $storeId = Auth::user()->store_id;

        $products = Product::where('store_id', $storeId)
            ->when($request->category_id, function ($q) use ($request) {
                $q->where('category_id', $request->category_id);
            })
            ->when($request->brand_id, function ($q) use ($request) {
                $q->where('brand_id', $request->brand_id);
            })
            ->when($request->search, function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%');
            })
            ->with(['store', 'brand', 'category', 'gstRate'])
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'status' => true,
            'products' => $products
        ], 200);
    }

    public function show($id)
    {
        $storeId = Auth::user()->store_id;
        $product = Product::where('store_id', $storeId)->where('id', $id)->first();

        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'product' => $product
        ], 200);
    }

    private function generateEan13Barcode()
    {
        // Generate first 12 digits
        $base = str_pad(mt_rand(0, 999999999999), 12, '0', STR_PAD_LEFT);

        // Calculate checksum
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $base[$i];
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }

        $checkDigit = (10 - ($sum % 10)) % 10;

        // Final barcode
        return $base . $checkDigit;
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'sku' => 'required|string|unique:products,sku',
                'brand_id' => 'nullable|integer',
                'category_id' => 'nullable|integer',
                'hsn_code' => 'nullable|string',
                'gst_rate_id' => 'nullable|numeric',
                'mrp' => 'nullable|numeric',
                'selling_price' => 'nullable|numeric',
                'cost_price' => 'nullable|numeric',
            ]);

            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            do {
                $barcode = $this->generateEan13Barcode();
                $exists = Product::where('barcode', $barcode)->exists();
            } while ($exists);

            $product = Product::create([
                'store_id' => $storeId,
                'sku' => $request->sku,
                'barcode' => $barcode,
                'name' => $request->name,
                'brand_id' => $request->brand_id,
                'category_id' => $request->category_id,
                'hsn_code' => $request->hsn_code,
                'gst_rate_id' => $request->gst_rate_id,
                'mrp' => $request->mrp,
                'selling_price' => $request->selling_price,
                'cost_price' => $request->cost_price,
                'created_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Product created successfully',
                'product' => $product
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while creating the product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'sku' => 'required|string|unique:products,sku,' . $id,
                'brand_id' => 'nullable|integer',
                'category_id' => 'nullable|integer',
                'hsn_code' => 'nullable|string',
                'gst_rate_id' => 'nullable|numeric',
                'mrp' => 'nullable|numeric',
                'selling_price' => 'nullable|numeric',
                'cost_price' => 'nullable|numeric',
            ]);

            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $product = Product::where('store_id', $storeId)->where('id', $id)->first();

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Product not found'
                ], 404);
            }

            $product->update([
                'name' => $request->name,
                'sku' => $request->sku,
                'brand_id' => $request->brand_id,
                'category_id' => $request->category_id,
                'hsn_code' => $request->hsn_code,
                'gst_rate_id' => $request->gst_rate_id,
                'mrp' => $request->mrp,
                'selling_price' => $request->selling_price,
                'cost_price' => $request->cost_price,
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Product updated successfully',
                'product' => $product
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while updating the product',
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

            $product = Product::where('store_id', $storeId)->where('id', $id)->first();

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Product not found'
                ], 404);
            }

            $product->delete();

            return response()->json([
                'status' => true,
                'message' => 'Product deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while deleting the product.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function barcodeImage($id)
    {
        try {
            $storeId = Auth::user()->store_id;

            $product = Product::where('store_id', $storeId)->where('id', $id)->first();

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Product not found'
                ], 404);
            }

            // Validate barcode length
            if (strlen($product->barcode) !== 13) {
                return response()->json(['error' => 'Invalid EAN-13 barcode'], 422);
            }

            // Generate PNG (base64)
            $pngBase64 = DNS1D::getBarcodePNG($product->barcode, 'EAN13', 2.5, 80);
            $png = base64_decode($pngBase64);

            return response($png, 200)
                ->header('Content-Type', 'image/png')
                ->header('Content-Length', strlen($png));
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while generating barcode image.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
