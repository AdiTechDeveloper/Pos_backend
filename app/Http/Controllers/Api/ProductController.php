<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Milon\Barcode\Facades\DNS1DFacade as DNS1D;

class ProductController extends Controller
{
    public function getAllProducts(Request $request)
    {
        $user = Auth::user();
        $role = $user->role;
        $storeId = $user->store_id;

        $query = Product::where('store_id', $storeId)->with('brand', 'category', 'gstRate');

        if ($role === 'manager') {
            $assignedBranchIds = DB::table('branch_staff')
                ->where('user_id', $user->id)
                ->pluck('branch_id')
                ->toArray();

            if (empty($assignedBranchIds)) {
                return response()->json(['status' => true, 'products' => []], 200);
            }

            if ($request->filled('branch_id')) {
                $requestedBranchId = (int) $request->branch_id;

                if (! in_array($requestedBranchId, $assignedBranchIds)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized access to this branch products.',
                    ], 403);
                }

                $targetBranchIds = [$requestedBranchId];
            } else {
                $targetBranchIds = $assignedBranchIds;
            }

            $staffUserIds = DB::table('branch_staff')
                ->whereIn('branch_id', $targetBranchIds)
                ->pluck('user_id')
                ->toArray();

            $query->whereIn('created_by', $staffUserIds);
        } elseif ($role === 'admin') {
            if ($request->filled('branch_id')) {
                $requestedBranchId = $request->branch_id;

                $staffUserIds = DB::table('branch_staff')
                    ->where('branch_id', $requestedBranchId)
                    ->pluck('user_id')
                    ->toArray();

                $query->whereIn('created_by', $staffUserIds);
            }
        }

        $products = $query->get();

        return response()->json([
            'status' => true,
            'products' => $products,
        ], 200);
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        $storeId = $user->store_id;
        $branchIds = $user->branches->pluck('id')->toArray();

        $showOutOfStock = $request->boolean('show_out_of_stock', false); // default: hide

        $products = Product::where('products.store_id', $storeId)
            ->when($request->category_id, function ($q) use ($request) {
                $q->where('category_id', $request->category_id);
            })
            ->when($request->brand_id, function ($q) use ($request) {
                $q->where('brand_id', $request->brand_id);
            })
            ->when($request->search, function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%');
            })
            ->with(['store', 'brand', 'category', 'gstRate'])
            ->with(['inventories' => function ($q) use ($branchIds) {
                $q->whereIn('branch_id', $branchIds)
                    ->whereColumn('sold_qty', '<', 'qty')
                    ->where(function ($q2) {
                        $q2->whereNull('expiry_date')
                            ->orWhere('expiry_date', '>=', now()->toDateString());
                    })
                    ->orderBy('expiry_date', 'asc');
            }])
            ->addSelect([
                'total_stock' => Inventory::selectRaw('COALESCE(SUM(qty - sold_qty), 0)')
                    ->whereColumn('inventories.product_id', 'products.id')
                    ->whereIn('inventories.branch_id', $branchIds)
                    ->whereColumn('inventories.sold_qty', '<', 'inventories.qty')
                    ->where(function ($q) {
                        $q->whereNull('inventories.expiry_date')
                            ->orWhere('inventories.expiry_date', '>=', now()->toDateString());
                    }),
            ])
            ->addSelect([
                'nearest_expiry' => Inventory::selectRaw('MIN(expiry_date)')
                    ->whereColumn('inventories.product_id', 'products.id')
                    ->whereIn('inventories.branch_id', $branchIds)
                    ->whereColumn('inventories.sold_qty', '<', 'inventories.qty')
                    ->where('inventories.expiry_date', '>=', now()->toDateString()),
            ])
            ->orderByRaw('nearest_expiry IS NULL, nearest_expiry ASC')
            ->get();

        if (! $showOutOfStock) {
            $products = $products->filter(function ($product) {
                return (float) $product->total_stock > 0;
            })->values();
        }

        $products = $products->map(function ($product) {
            $batches = $product->inventories->map(function ($inv) {
                return [
                    'id' => $inv->id,
                    'batch_no' => $inv->batch_no,
                    'mrp' => $inv->mrp,
                    'cost_price' => $inv->cost_price,
                    'selling_price' => $inv->selling_price,
                    'qty_available' => $inv->qty - $inv->sold_qty,
                    'expiry_date' => $inv->expiry_date,
                    'is_opening' => $inv->is_opening,
                    'batch_barcode' => $inv->batch_barcode,
                    'free' => $inv->free,
                    'qty' => $inv->qty,
                    'sold_qty' => $inv->sold_qty,
                ];
            })->values();

            $prices = $batches->pluck('selling_price')->filter(fn ($p) => ! is_null($p));

            $data = $product->toArray();
            $data['min_price'] = $prices->min();
            $data['max_price'] = $prices->max();
            $data['has_multiple_prices'] = $prices->unique()->count() > 1;
            $data['batch_count'] = $batches->count();
            $data['batches'] = $batches;
            unset($data['inventories']);

            return $data;
        });

        return response()->json([
            'status' => true,
            'products' => $products,
        ], 200);
    }

    public function show($id)
    {
        $storeId = Auth::user()->store_id;
        $product = Product::where('store_id', $storeId)->where('id', $id)->first();

        if (! $product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'product' => $product,
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
        return $base.$checkDigit;
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'sku' => 'nullable|string|unique:products,sku',
                'barcode' => 'nullable|string', // This will be the Manufacturer barcode if scanned
                'is_price_override' => 'nullable|boolean',
            ]);

            $user = Auth::user();
            if (! in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $barcode = $request->barcode;

            if (empty($barcode)) {
                do {
                    $barcode = $this->generateEan13Barcode();
                    $exists = Product::where('barcode', $barcode)->exists();
                } while ($exists);
            } else {
                $exists = Product::where('barcode', $barcode)->exists();
                if ($exists) {
                    return response()->json(['status' => false, 'message' => 'Product with this barcode already exists'], 422);
                }
            }

            $product = Product::create([
                'store_id' => $storeId,
                'sku' => $request->sku,
                'barcode' => $barcode, // Master barcode
                'name' => $request->name,
                'brand_id' => $request->brand_id,
                'category_id' => $request->category_id,
                'hsn_code' => $request->hsn_code,
                'gst_rate_id' => $request->gst_rate_id,
                'gst_inclusive' => $request->gst_inclusive ?? 0,
                'is_price_override' => $request->input('is_price_override', 0),
                'created_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Product created successfully',
                'product' => $product,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while creating the product',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'is_price_override' => 'nullable|boolean',
            ]);

            $user = Auth::user();

            if (! in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $product = Product::where('store_id', $storeId)->where('id', $id)->first();

            if (! $product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Product not found',
                ], 404);
            }

            $product->update([
                'name' => $request->name,
                'sku' => $request->sku,
                'brand_id' => $request->brand_id,
                'category_id' => $request->category_id,
                'hsn_code' => $request->hsn_code,
                'gst_rate_id' => $request->gst_rate_id,
                'gst_inclusive' => $request->gst_inclusive ?? 0,
                'is_price_override' => $request->input('is_price_override', 0),
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Product updated successfully',
                'product' => $product,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while updating the product',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateSellingPrice(Request $request, $inventoryId)
    {
        try {
            $request->validate([
                'selling_price' => 'required|numeric|min:0.01',
            ]);

            // Find inventory
            $inventory = Inventory::with('product')->find($inventoryId);

            if (! $inventory) {
                return response()->json([
                    'status' => false,
                    'message' => 'Inventory not found',
                ], 404);
            }

            // Check is_override from PRODUCTS table
            $product = $inventory->product;

            if (! $product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Product not found',
                ], 404);
            }

            // Product must have is_override = 1
            if ((int) $product->is_price_override !== 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'Price override is not allowed for this product',
                    'is_price_override' => 0,
                ], 403);
            }

            // Update price in INVENTORY table
            $inventory->selling_price = $request->selling_price;
            $inventory->save();

            return response()->json([
                'status' => true,
                'message' => 'Selling price updated successfully',

                'inventory_id' => $inventory->id,
                'product_id' => $product->id,

                // Updated inventory price
                'selling_price' => (float) $inventory->selling_price,

                // From PRODUCTS table
                'is_price_override' => (int) $product->is_price_override,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update selling price',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = Auth::user();

            if (! in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $product = Product::where('store_id', $storeId)->where('id', $id)->first();

            if (! $product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Product not found',
                ], 404);
            }

            $product->delete();

            return response()->json([
                'status' => true,
                'message' => 'Product deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while deleting the product.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function barcodeImage($id)
    {
        try {
            $storeId = Auth::user()->store_id;

            $product = Product::where('store_id', $storeId)->where('id', $id)->first();

            if (! $product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Product not found',
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
                ->header('X-Product-Name', $product->name)
                ->header('X-Price', $product->selling_price)
                ->header('Content-Length', strlen($png));
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while generating barcode image.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getExpiredStock(Request $request)
    {
        $user = Auth::user();

        if (! in_array($user->role, ['admin', 'manager'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
                'data' => [],
                'total_loss' => 0,
            ], 403);
        }

        $range = $request->input('date_range', 'all');
        $fromDate = null;
        $toDate = null;

        if ($range === 'custom') {
            if ($request->filled('date_from')) {
                $fromDate = Carbon::parse($request->date_from)->startOfDay();
            }
            if ($request->filled('date_to')) {
                $toDate = Carbon::parse($request->date_to)->endOfDay();
            }
        } elseif ($range !== 'all') {
            [$fromDate, $toDate] = match ($range) {
                'today' => [
                    Carbon::today()->startOfDay(),
                    Carbon::today()->endOfDay(),
                ],
                'yesterday' => [
                    Carbon::yesterday()->startOfDay(),
                    Carbon::yesterday()->endOfDay(),
                ],
                'last_7_days' => [
                    Carbon::now()->subDays(6)->startOfDay(),
                    Carbon::now()->endOfDay(),
                ],
                'this_month' => [
                    Carbon::now()->startOfMonth()->startOfDay(),
                    Carbon::now()->endOfDay(),
                ],
                default => [null, null],
            };
        }

        if ($request->filled('from_date')) {
            $fromDate = Carbon::parse($request->from_date)->startOfDay();
        }

        if ($request->filled('to_date')) {
            $toDate = Carbon::parse($request->to_date)->endOfDay();
        }

        if ($fromDate && ! $toDate) {
            $toDate = Carbon::now()->endOfDay();
        }

        if (! $fromDate && $toDate) {
            $fromDate = Carbon::create(2000, 1, 1)->startOfDay();
        }

        if ($user->role === 'admin') {
            $branchQuery = DB::table('branches')->where('store_id', $user->store_id);

            if ($request->filled('branch_id')) {
                $requestedBranchId = (int) $request->branch_id;
                $branchExists = $branchQuery->where('id', $requestedBranchId)->exists();

                if (! $branchExists) {
                    return response()->json([
                        'status' => false,
                        'message' => 'This branch does not belong to your store',
                        'data' => [],
                        'total_loss' => 0,
                    ], 403);
                }

                $branchIds = [$requestedBranchId];
            } else {
                $branchIds = $branchQuery->pluck('id')->toArray();
            }
        } else {
            $branchIds = $user->branches()->pluck('branches.id')->toArray();

            if ($request->filled('branch_id')) {
                $requestedBranchId = (int) $request->branch_id;

                if (! in_array($requestedBranchId, $branchIds, true)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'This branch is not assigned to you',
                        'data' => [],
                        'total_loss' => 0,
                    ], 403);
                }

                $branchIds = [$requestedBranchId];
            }
        }

        if (empty($branchIds)) {
            return response()->json([
                'status' => true,
                'message' => 'No branch available for this user',
                'data' => [],
                'total_loss' => 0,
            ], 200);
        }

        $query = Inventory::with('product:id,name', 'branch:id,name')
            ->whereIn('branch_id', $branchIds)
            ->whereNotNull('expiry_date')
            ->whereColumn('sold_qty', '<', 'qty');

        if ($fromDate) {
            $query->whereDate('expiry_date', '>=', $fromDate->toDateString());
        }

        if ($toDate) {
            $query->whereDate('expiry_date', '<=', $toDate->toDateString());
        }

        if (! $fromDate && ! $toDate) {
            $query->where('expiry_date', '<', now()->toDateString());
        } else {
            $query->where('expiry_date', '<=', now()->toDateString());
        }

        $expiredBatches = $query
            ->select(
                'id',
                'product_id',
                'branch_id',
                'batch_no',
                'batch_barcode',
                'expiry_date',
                'cost_price',
                'selling_price',
                'qty',
                'sold_qty'
            )
            ->get()
            ->map(function ($item) {
                $expiredQty = $item->qty - $item->sold_qty;

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name ?? '—',
                    'branch_id' => $item->branch_id,
                    'branch_name' => $item->branch->name ?? '—',
                    'batch_no' => $item->batch_no,
                    'batch_barcode' => $item->batch_barcode,
                    'expiry_date' => $item->expiry_date,
                    'expired_qty' => $expiredQty,
                    'cost_price' => $item->cost_price,
                    'loss_amount' => $expiredQty * $item->cost_price,
                ];
            });

        return response()->json([
            'status' => true,
            'data' => $expiredBatches,
            'total_loss' => $expiredBatches->sum('loss_amount'),
            'branch_id' => $request->branch_id ?? 'all',
            'from_date' => $fromDate?->toDateString(),
            'to_date' => $toDate?->toDateString(),
        ]);
    }
}
