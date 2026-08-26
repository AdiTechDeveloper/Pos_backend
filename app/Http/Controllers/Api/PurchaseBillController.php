<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\GstRate;
use App\Models\Inventory;
use App\Models\ItcEntry;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\PurchaseLine;
use App\Models\Store;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseBillController extends Controller
{
    public function index()
    {
        try {
            $user = Auth::user();

            $query = PurchaseBill::with([
                'store',
                'supplier',
                'branch:id,name',
                'supplier:id,name',
                'lines.product:id,name,sku',
                'lines.inventory',
            ]);

            if ($user->role === 'manager') {
                $managerBranchIds = $user->branches()->pluck('branches.id');
                $query->whereIn('branch_id', $managerBranchIds);
            }

            if ($user->role === 'admin') {
                $query->whereHas('branch', function ($q) use ($user) {
                    $q->where('store_id', $user->store_id);
                });
            }

            $purchaseBills = $query
                ->orderBy('id', 'DESC')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $purchaseBills,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $user = Auth::user();

            $bill = PurchaseBill::with([
                'branch:id,name',
                'supplier:id,name',
                'lines.product:id,name,sku',
                'lines.inventory',
            ])->find($id);

            if (! $bill) {
                return response()->json([
                    'status' => false,
                    'message' => 'Purchase bill not found',
                ], 404);
            }

            if ($user->role === 'admin') {
                if ($bill->store_id != $user->store_id) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized - Admin can only access purchase bills of their own store',
                    ], 403);
                }

                return response()->json(['status' => true, 'data' => $bill]);
            }

            if ($user->role === 'manager') {
                if ($bill->store_id != $user->store_id) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized - Manager can access only their store purchase bills',
                    ], 403);
                }

                $managerBranchIds = $user->branches()->pluck('branches.id');

                if (! $managerBranchIds->contains($bill->branch_id)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized - Manager can access purchase bills of their assigned branch only',
                    ], 403);
                }

                return response()->json(['status' => true, 'data' => $bill]);
            }

            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function generateBatchNo($productId)
    {
        $date = now()->format('Ymd');

        $lastBatch = Inventory::where('product_id', $productId)
            ->whereDate('created_at', now())
            ->where('batch_no', 'like', "POS-{$productId}-{$date}-%")
            ->orderByDesc('id')
            ->first();

        if ($lastBatch) {
            $lastNumber = (int) substr($lastBatch->batch_no, -3);
            $nextNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $nextNumber = '001';
        }

        return "POS-{$productId}-{$date}-{$nextNumber}";
    }

    private function generateInwardNumber($branchId)
    {
        // Financial Year prefix (e.g., 2425)
        $fy = date('m') >= 4 ? date('y').(date('y') + 1) : (date('y') - 1).date('y');

        $lastSequence = PurchaseBill::withTrashed() 
            ->where('branch_id', $branchId)
            ->max('inward_sequence') ?? 0;

        $nextSequence = $lastSequence + 1;

        // Formatted Inward Number e.g., INW/2425/0001
        $inwardNo = 'INW/'.$fy.'/'.str_pad($nextSequence, 4, '0', STR_PAD_LEFT);

        return [
            'inward_no' => $inwardNo,
            'inward_sequence' => $nextSequence,
        ];
    }

    // public function store(Request $request)
    // {
    //     $validated = $request->validate([
    //         'branch_id' => 'required|integer',
    //         'supplier_id' => 'required|integer',
    //         'bill_no' => [
    //             'required',
    //             'string',
    //             \Illuminate\Validation\Rule::unique('purchase_bills')->where(
    //                 fn ($q) => $q->where('supplier_id', $request->supplier_id)
    //             ),
    //         ],
    //         'bill_date' => 'required|date',
    //         'is_lost' => 'sometimes|in:0,1',
    //         'lines' => 'required|array|min:1',
    //         'lines.*.product_id' => 'required|integer',
    //         'lines.*.qty' => 'required|numeric|min:0.0001',
    //         'lines.*.free_qty' => 'nullable|numeric|min:0',
    //         'lines.*.purchase_rate' => 'required|numeric|min:0',
    //         'lines.*.mrp' => 'required|numeric|min:0',
    //         'lines.*.selling_price' => 'required|numeric|min:0',
    //         'lines.*.gst_rate_id' => 'required|integer',
    //         'lines.*.batch_no' => 'nullable|string',
    //         'lines.*.expiry_date' => 'nullable|date',
    //         'lines.*.discount' => 'nullable|numeric|min:0',
    //         'lines.*.discount_type' => 'nullable|string|in:percent,fixed',
    //         'lines.*.is_opening' => 'sometimes|in:0,1',
    //     ]);

    //     try {
    //         DB::beginTransaction();

    //         $user = Auth::user();
    //         $storeId = $user->store_id;
    //         $supplier = Supplier::findOrFail($validated['supplier_id']);

    //         $branch = Branch::findOrFail($validated['branch_id']);
    //         $originState = ($user->role === 'admin')
    //             ? Store::findOrFail($storeId)->state
    //             : $branch->state;
    //         $isIntra = ($originState === $supplier->state);

    //         $bill = PurchaseBill::create([
    //             'store_id' => $storeId,
    //             'branch_id' => $validated['branch_id'],
    //             'supplier_id' => $validated['supplier_id'],
    //             'bill_no' => $validated['bill_no'],
    //             'bill_date' => $validated['bill_date'],
    //             'is_lost' => $validated['is_lost'] ?? 0,
    //             'created_by' => $user->id,
    //         ]);

    //         $totals = ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];

    //         foreach ($validated['lines'] as $lineData) {
    //             $product = Product::findOrFail($lineData['product_id']);
    //             $gst = GstRate::findOrFail($lineData['gst_rate_id']);

    //             $qty = (float) $lineData['qty'];
    //             $freeQty = (float) ($lineData['free_qty'] ?? 0);
    //             $purchaseRate = (float) $lineData['purchase_rate'];
    //             $mrp = (float) $lineData['mrp'];
    //             $sellingPrice = (float) $lineData['selling_price'];

    //             $discount = isset($lineData['discount']) ? (float) $lineData['discount'] : 0.0;
    //             $discountType = $lineData['discount_type'] ?? null;

    //             $grossValue = round($qty * $purchaseRate, 2);
    //             $discountAmount = ($discountType === 'percent')
    //                 ? round($grossValue * ($discount / 100), 2)
    //                 : round($discount, 2);
    //             $taxable = max(0, round($grossValue - $discountAmount, 2));

    //             $taxRate = $gst->rate;
    //             $cgst = $isIntra ? round(($taxable * ($taxRate / 2)) / 100, 2) : 0;
    //             $sgst = $isIntra ? round(($taxable * ($taxRate / 2)) / 100, 2) : 0;
    //             $igst = ! $isIntra ? round(($taxable * $taxRate) / 100, 2) : 0;

    //             $hasBatch = ! empty($lineData['batch_no']);
    //             $batchNo = $hasBatch ? $lineData['batch_no'] : $this->generateBatchNo($product->id);

    //             $line = PurchaseLine::create([
    //                 'purchase_bill_id' => $bill->id,
    //                 'product_id' => $product->id,
    //                 'qty' => $qty,
    //                 'free_qty' => $freeQty,
    //                 'purchase_rate' => $purchaseRate,
    //                 'mrp' => $mrp,
    //                 'selling_price' => $sellingPrice,
    //                 'taxable_value' => $taxable,
    //                 'cgst' => $cgst,
    //                 'sgst' => $sgst,
    //                 'igst' => $igst,
    //                 'gst_rate_id' => $lineData['gst_rate_id'],
    //                 'discount_type' => $discountType,
    //                 'discount' => $discount,
    //                 'hsn_code' => $lineData['hsn_code'] ?? $product->hsn_code,
    //                 'batch_no' => $batchNo,
    //                 'expiry_date' => $lineData['expiry_date'] ?? null,
    //                 'is_opening' => (int) ($lineData['is_opening'] ?? 0),
    //             ]);

    //             $existingBatch = null;

    //             if ($hasBatch) {
    //                 $existingBatch = Inventory::where('product_id', $product->id)
    //                     ->where('branch_id', $validated['branch_id'])
    //                     ->where('selling_price', $sellingPrice)
    //                     ->where('mrp', $mrp)
    //                     ->where('batch_no', $batchNo)
    //                     ->where('cost_price', $purchaseRate)
    //                     ->where('free', 0)
    //                     ->first();
    //             }

    //             if ($existingBatch) {
    //                 $existingBatch->increment('qty', $qty);
    //                 $batchBarcode = $existingBatch->batch_barcode;
    //                 $existingBatch->amount = $existingBatch->qty * $existingBatch->cost_price;
    //                 $existingBatch->save();
    //             } else {
    //                 $batchBarcode = $product->barcode;
    //                 if (Inventory::where('batch_barcode', $batchBarcode)->exists()) {
    //                     $batchBarcode = $product->barcode.'-'.strtoupper(Str::random(4));
    //                 }

    //                 $isOpening = (int) ($lineData['is_opening'] ?? 0);

    //                 Inventory::create([
    //                     'product_id' => $product->id,
    //                     'branch_id' => $validated['branch_id'],
    //                     'purchase_bill_id' => $bill->id,
    //                     'purchase_line_id' => $line->id,
    //                     'batch_barcode' => $batchBarcode,
    //                     'batch_no' => $batchNo,
    //                     'mrp' => $mrp,
    //                     'selling_price' => $sellingPrice,
    //                     'cost_price' => $purchaseRate,
    //                     'qty' => $qty,
    //                     'sold_qty' => 0,
    //                     'free' => false,
    //                     'rate' => $purchaseRate,
    //                     'amount' => $qty * $purchaseRate,
    //                     'expiry_date' => $lineData['expiry_date'] ?? null,
    //                     'is_opening' => $isOpening,
    //                 ]);
    //             }

    //             if ($freeQty > 0) {
    //                 $isOpening = (int) ($lineData['is_opening'] ?? 0);

    //                 $existingFreeBatch = Inventory::where('product_id', $product->id)
    //                     ->where('branch_id', $validated['branch_id'])
    //                     ->where('batch_no', $batchNo)
    //                     ->where('mrp', $mrp)
    //                     ->where('selling_price', $sellingPrice)
    //                     ->where('cost_price', 0)
    //                     ->where('free', 1)
    //                     ->first();

    //                 if ($existingFreeBatch) {
    //                     $existingFreeBatch->increment('qty', $freeQty);
    //                 } else {
    //                     Inventory::create([
    //                         'product_id' => $product->id,
    //                         'branch_id' => $validated['branch_id'],
    //                         'purchase_bill_id' => $bill->id,
    //                         'purchase_line_id' => $line->id,
    //                         'batch_barcode' => $batchBarcode,
    //                         'batch_no' => $batchNo,
    //                         'mrp' => $mrp,
    //                         'selling_price' => $sellingPrice,
    //                         'cost_price' => 0,
    //                         'qty' => $freeQty,
    //                         'sold_qty' => 0,
    //                         'free' => true,
    //                         'rate' => 0,
    //                         'amount' => 0,
    //                         'expiry_date' => $lineData['expiry_date'] ?? null,
    //                         'is_opening' => $isOpening,
    //                     ]);
    //                 }
    //             }

    //             ItcEntry::create([
    //                 'purchase_bill_id' => $bill->id,
    //                 'purchase_line_id' => $line->id,
    //                 'product_id' => $product->id,
    //                 'cgst' => $cgst,
    //                 'sgst' => $sgst,
    //                 'igst' => $igst,
    //                 'total_itc' => round($cgst + $sgst + $igst, 2),
    //                 'created_by' => $user->id,
    //             ]);

    //             $totals['taxable'] += $taxable;
    //             $totals['cgst'] += $cgst;
    //             $totals['sgst'] += $sgst;
    //             $totals['igst'] += $igst;
    //         }

    //         $totalTax = $totals['cgst'] + $totals['sgst'] + $totals['igst'];

    //         $bill->update([
    //             'taxable_value' => $totals['taxable'],
    //             'cgst_amount' => $totals['cgst'],
    //             'sgst_amount' => $totals['sgst'],
    //             'igst_amount' => $totals['igst'],
    //             'total_tax' => $totalTax,
    //             'total_amount' => round($totals['taxable'] + $totalTax, 2),
    //             'received' => true,
    //         ]);

    //         DB::commit();

    //         return response()->json(['status' => true, 'message' => 'Success', 'bill' => $bill], 201);
    //     } catch (\Exception $e) {
    //         DB::rollBack();

    //         return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
    //     }
    // }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|integer',
            'supplier_id' => 'required|integer',
            'bill_no' => [
                'required',
                'string',
                \Illuminate\Validation\Rule::unique('purchase_bills')->where(
                    fn ($q) => $q->where('supplier_id', $request->supplier_id)
                ),
            ],
            'bill_date' => 'required|date',
            'is_lost' => 'sometimes|in:0,1',

            // New Global Fields
            'tax_type' => 'required|string|in:inclusive,exclusive',
            'settlement_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',

            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer',
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.free_qty' => 'nullable|numeric|min:0',
            'lines.*.purchase_rate' => 'required|numeric|min:0',
            'lines.*.mrp' => 'required|numeric|min:0',
            'lines.*.selling_price' => 'required|numeric|min:0',
            'lines.*.gst_rate_id' => 'required|integer',
            'lines.*.batch_no' => 'nullable|string',
            'lines.*.expiry_date' => 'nullable|date',
            'lines.*.discount' => 'nullable|numeric|min:0',
            'lines.*.discount_type' => 'nullable|string|in:percent,fixed',
            'lines.*.is_opening' => 'sometimes|in:0,1',
        ]);

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $storeId = $user->store_id;
            $supplier = Supplier::findOrFail($validated['supplier_id']);
            $branch = Branch::findOrFail($validated['branch_id']);

            // Generate Inward Details
            $inwardData = $this->generateInwardNumber($validated['branch_id']);

            $originState = ($user->role === 'admin')
                ? Store::findOrFail($storeId)->state
                : $branch->state;
            $isIntra = ($originState === $supplier->state);

            $taxType = $validated['tax_type']; // 'inclusive' or 'exclusive'

            // 1. Initial Bill Creation with New Fields Included
            $bill = PurchaseBill::create([
                'store_id' => $storeId,
                'branch_id' => $validated['branch_id'],
                'supplier_id' => $validated['supplier_id'],
                'inward_no' => $inwardData['inward_no'],
                'inward_sequence' => $inwardData['inward_sequence'],
                'bill_no' => $validated['bill_no'],
                'bill_date' => $validated['bill_date'],
                'is_lost' => $validated['is_lost'] ?? 0,
                'tax_type' => $taxType,
                'settlement_amount' => $validated['settlement_amount'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $totals = ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];

            foreach ($validated['lines'] as $lineData) {
                $product = Product::findOrFail($lineData['product_id']);
                $gst = GstRate::findOrFail($lineData['gst_rate_id']);

                $qty = (float) $lineData['qty'];
                $freeQty = (float) ($lineData['free_qty'] ?? 0);
                $enteredPurchaseRate = (float) $lineData['purchase_rate'];
                $mrp = (float) $lineData['mrp'];
                $sellingPrice = (float) $lineData['selling_price'];
                $taxRate = (float) $gst->rate;

                $discount = isset($lineData['discount']) ? (float) $lineData['discount'] : 0.0;
                $discountType = $lineData['discount_type'] ?? null;

                // 2. Tax Handling Core Logic
                if ($taxType === 'inclusive') {
                    // Extract pre-tax base rate from the inclusive rate
                    // Formula: Base Rate = Inclusive Rate / (1 + (Tax Rate / 100))
                    $calculatedBaseRate = $enteredPurchaseRate / (1 + ($taxRate / 100));

                    $grossValue = round($qty * $calculatedBaseRate, 2);
                    $discountAmount = ($discountType === 'percent')
                        ? round($grossValue * ($discount / 100), 2)
                        : round($discount, 2);

                    $taxable = max(0, round($grossValue - $discountAmount, 2));

                    // Calculate taxes straight out of the computed taxable base
                    $cgst = $isIntra ? round(($taxable * ($taxRate / 2)) / 100, 2) : 0;
                    $sgst = $isIntra ? round(($taxable * ($taxRate / 2)) / 100, 2) : 0;
                    $igst = ! $isIntra ? round(($taxable * $taxRate) / 100, 2) : 0;

                    // Inventory value should represent the net raw cost before tax
                    $inventoryCostPrice = round($calculatedBaseRate, 4);
                } else {
                    // Standard Tax Exclusive Logic
                    $grossValue = round($qty * $enteredPurchaseRate, 2);
                    $discountAmount = ($discountType === 'percent')
                        ? round($grossValue * ($discount / 100), 2)
                        : round($discount, 2);

                    $taxable = max(0, round($grossValue - $discountAmount, 2));

                    $cgst = $isIntra ? round(($taxable * ($taxRate / 2)) / 100, 2) : 0;
                    $sgst = $isIntra ? round(($taxable * ($taxRate / 2)) / 100, 2) : 0;
                    $igst = ! $isIntra ? round(($taxable * $taxRate) / 100, 2) : 0;

                    $inventoryCostPrice = $enteredPurchaseRate;
                }

                $hasBatch = ! empty($lineData['batch_no']);
                $batchNo = $hasBatch ? $lineData['batch_no'] : $this->generateBatchNo($product->id);

                // 3. Purchase Line Item Insertion
                $line = PurchaseLine::create([
                    'purchase_bill_id' => $bill->id,
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'free_qty' => $freeQty,
                    'purchase_rate' => $enteredPurchaseRate,
                    'mrp' => $mrp,
                    'selling_price' => $sellingPrice,
                    'taxable_value' => $taxable,
                    'cgst' => $cgst,
                    'sgst' => $sgst,
                    'igst' => $igst,
                    'gst_rate_id' => $lineData['gst_rate_id'],
                    'discount_type' => $discountType,
                    'discount' => $discount,
                    'hsn_code' => $lineData['hsn_code'] ?? $product->hsn_code,
                    'batch_no' => $batchNo,
                    'expiry_date' => $lineData['expiry_date'] ?? null,
                    'is_opening' => (int) ($lineData['is_opening'] ?? 0),
                ]);

                // 4. Stock Allocation (Using Calculated Pre-Tax Cost Price)
                $existingBatch = null;
                if ($hasBatch) {
                    $existingBatch = Inventory::where('product_id', $product->id)
                        ->where('branch_id', $validated['branch_id'])
                        ->where('selling_price', $sellingPrice)
                        ->where('mrp', $mrp)
                        ->where('batch_no', $batchNo)
                        ->where('cost_price', $inventoryCostPrice)
                        ->where('free', 0)
                        ->first();
                }

                if ($existingBatch) {
                    $existingBatch->increment('qty', $qty);
                    $batchBarcode = $existingBatch->batch_barcode;
                    $existingBatch->amount = $existingBatch->qty * $existingBatch->cost_price;
                    $existingBatch->save();
                } else {
                    $batchBarcode = $product->barcode;
                    if (Inventory::where('batch_barcode', $batchBarcode)->exists()) {
                        $batchBarcode = $product->barcode.'-'.strtoupper(Str::random(4));
                    }

                    $isOpening = (int) ($lineData['is_opening'] ?? 0);

                    Inventory::create([
                        'product_id' => $product->id,
                        'branch_id' => $validated['branch_id'],
                        'purchase_bill_id' => $bill->id,
                        'purchase_line_id' => $line->id,
                        'batch_barcode' => $batchBarcode,
                        'batch_no' => $batchNo,
                        'mrp' => $mrp,
                        'selling_price' => $sellingPrice,
                        'cost_price' => $inventoryCostPrice,
                        'qty' => $qty,
                        'sold_qty' => 0,
                        'free' => false,
                        'rate' => $inventoryCostPrice,
                        'amount' => $qty * $inventoryCostPrice,
                        'expiry_date' => $lineData['expiry_date'] ?? null,
                        'is_opening' => $isOpening,
                    ]);
                }

                // Free items entry sequence
                if ($freeQty > 0) {
                    $isOpening = (int) ($lineData['is_opening'] ?? 0);

                    $existingFreeBatch = Inventory::where('product_id', $product->id)
                        ->where('branch_id', $validated['branch_id'])
                        ->where('batch_no', $batchNo)
                        ->where('mrp', $mrp)
                        ->where('selling_price', $sellingPrice)
                        ->where('cost_price', 0)
                        ->where('free', 1)
                        ->first();

                    if ($existingFreeBatch) {
                        $existingFreeBatch->increment('qty', $freeQty);
                    } else {
                        Inventory::create([
                            'product_id' => $product->id,
                            'branch_id' => $validated['branch_id'],
                            'purchase_bill_id' => $bill->id,
                            'purchase_line_id' => $line->id,
                            'batch_barcode' => $batchBarcode,
                            'batch_no' => $batchNo,
                            'mrp' => $mrp,
                            'selling_price' => $sellingPrice,
                            'cost_price' => 0,
                            'qty' => $freeQty,
                            'sold_qty' => 0,
                            'free' => true,
                            'rate' => 0,
                            'amount' => 0,
                            'expiry_date' => $lineData['expiry_date'] ?? null,
                            'is_opening' => $isOpening,
                        ]);
                    }
                }

                // ITC Record keeping
                ItcEntry::create([
                    'purchase_bill_id' => $bill->id,
                    'purchase_line_id' => $line->id,
                    'product_id' => $product->id,
                    'cgst' => $cgst,
                    'sgst' => $sgst,
                    'igst' => $igst,
                    'total_itc' => round($cgst + $sgst + $igst, 2),
                    'created_by' => $user->id,
                ]);

                $totals['taxable'] += $taxable;
                $totals['cgst'] += $cgst;
                $totals['sgst'] += $sgst;
                $totals['igst'] += $igst;
            }

            $totalTax = $totals['cgst'] + $totals['sgst'] + $totals['igst'];
            $calculatedGrandTotal = round($totals['taxable'] + $totalTax, 2);

            // 5. Final Bill Summary Update
            $bill->update([
                'taxable_value' => $totals['taxable'],
                'cgst_amount' => $totals['cgst'],
                'sgst_amount' => $totals['sgst'],
                'igst_amount' => $totals['igst'],
                'total_tax' => $totalTax,
                'total_amount' => $calculatedGrandTotal,
                'received' => true,
            ]);

            DB::commit();

            return response()->json(['status' => true, 'message' => 'Success', 'bill' => $bill], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // public function update(Request $request, $id)
    // {
    //     $request->validate([
    //         'supplier_id' => 'required|integer',
    //         'bill_no' => 'sometimes|string',
    //         'bill_date' => 'required|date',
    //         'is_lost' => 'sometimes|in:0,1',
    //         'lines' => 'required|array|min:1',
    //         'lines.*.product_id' => 'required|integer',
    //         'lines.*.qty' => 'required|numeric|min:0.0001',
    //         'lines.*.free_qty' => 'nullable|numeric|min:0',
    //         'lines.*.purchase_rate' => 'required|numeric|min:0',
    //         'lines.*.mrp' => 'required|numeric|min:0',
    //         'lines.*.selling_price' => 'required|numeric|min:0',
    //         'lines.*.gst_rate_id' => 'required|integer',
    //         'lines.*.batch_no' => 'nullable|string',
    //         'lines.*.expiry_date' => 'nullable|date',
    //         'lines.*.discount' => 'nullable|numeric|min:0',
    //         'lines.*.discount_type' => 'nullable|in:percent,fixed',
    //         'lines.*.hsn_code' => 'nullable|string',
    //     ]);

    //     DB::beginTransaction();

    //     try {
    //         $bill = PurchaseBill::with('lines.inventory')->findOrFail($id);
    //         $branchId = $bill->branch_id;
    //         $user = Auth::user();

    //         // STEP 1: Delete only unsold inventory
    //         foreach ($bill->lines as $oldLine) {
    //             Inventory::where('purchase_line_id', $oldLine->id)
    //                 ->where('sold_qty', 0)
    //                 ->delete();
    //         }

    //         // Delete purchase lines + ITC
    //         PurchaseLine::where('purchase_bill_id', $bill->id)->delete();
    //         ItcEntry::where('purchase_bill_id', $bill->id)->delete();

    //         // STEP 2: Update bill header
    //         $bill->update([
    //             'supplier_id' => $request->supplier_id,
    //             'bill_no' => $request->bill_no ?? $bill->bill_no,
    //             'bill_date' => $request->bill_date,
    //             'is_lost' => $request->is_lost ?? 0,
    //             'updated_by' => $user->id,
    //         ]);

    //         // STEP 3: Create new lines + inventory + ITC
    //         $totalTaxable = $totalCgst = $totalSgst = $totalIgst = 0;
    //         $processedProducts = [];

    //         foreach ($request->lines as $line) {
    //             $product = Product::findOrFail($line['product_id']);

    //             $qty = (float) $line['qty'];
    //             $freeQty = (float) ($line['free_qty'] ?? 0);
    //             $rate = (float) $line['purchase_rate'];
    //             $mrp = (float) $line['mrp'];
    //             $sellingPrice = (float) $line['selling_price'];
    //             $gstRateId = $line['gst_rate_id'];

    //             $gstRate = optional(GstRate::find($gstRateId))->rate ?? 0;
    //             $discountType = $line['discount_type'] ?? null;
    //             $discount = (float) ($line['discount'] ?? 0);

    //             $gross = $qty * $rate;
    //             $discountAmount = $discountType === 'percent'
    //                 ? round($gross * $discount / 100, 2)
    //                 : round($discount, 2);
    //             $taxable = round(max(0, $gross - $discountAmount), 2);

    //             // GST
    //             $storeState = $user->store->state;
    //             $branchState = $user->branches->first()->state;
    //             $isIntra = $storeState === $branchState;

    //             if ($isIntra) {
    //                 $cgst = round($taxable * ($gstRate / 2) / 100, 2);
    //                 $sgst = round($taxable * ($gstRate / 2) / 100, 2);
    //                 $igst = 0;
    //             } else {
    //                 $cgst = 0;
    //                 $sgst = 0;
    //                 $igst = round(($taxable * $gstRate) / 100, 2);
    //             }

    //             $totalGst = $cgst + $sgst + $igst;

    //             // Batch no
    //             $hasBatch = ! empty($line['batch_no']);
    //             $batchNo = $hasBatch ? $line['batch_no'] : $this->generateBatchNo($product->id);

    //             // Create Purchase Line
    //             $purchaseLine = PurchaseLine::create([
    //                 'purchase_bill_id' => $bill->id,
    //                 'product_id' => $product->id,
    //                 'gst_rate_id' => $gstRateId,
    //                 'hsn_code' => $line['hsn_code'] ?? $product->hsn_code,
    //                 'taxable_value' => $taxable,
    //                 'qty' => $qty,
    //                 'free_qty' => $freeQty,
    //                 'purchase_rate' => $rate,
    //                 'mrp' => $mrp,
    //                 'selling_price' => $sellingPrice,
    //                 'discount_type' => $discountType,
    //                 'discount' => $discount,
    //                 'amount' => $taxable,
    //                 'cgst' => $cgst,
    //                 'sgst' => $sgst,
    //                 'igst' => $igst,
    //                 'total_gst' => $totalGst,
    //                 'batch_no' => $batchNo,
    //                 'expiry_date' => $line['expiry_date'] ?? null,
    //             ]);

    //             // Barcode generation
    //             $batchBarcode = $product->barcode;
    //             if (Inventory::where('batch_barcode', $batchBarcode)->exists()) {
    //                 $batchBarcode = $product->barcode.'-'.strtoupper(Str::random(4));
    //             }

    //             // Normal QTY Inventory
    //             Inventory::create([
    //                 'product_id' => $product->id,
    //                 'branch_id' => $branchId,
    //                 'purchase_bill_id' => $bill->id,
    //                 'purchase_line_id' => $purchaseLine->id,
    //                 'batch_barcode' => $batchBarcode,
    //                 'batch_no' => $batchNo,
    //                 'mrp' => $mrp,
    //                 'selling_price' => $sellingPrice,
    //                 'cost_price' => $rate,
    //                 'qty' => $qty,
    //                 'sold_qty' => 0,
    //                 'free' => 0,
    //                 'rate' => $rate,
    //                 'amount' => $taxable,
    //                 'expiry_date' => $line['expiry_date'] ?? null,
    //             ]);

    //             // Free QTY Inventory
    //             if ($freeQty > 0) {
    //                 Inventory::create([
    //                     'product_id' => $product->id,
    //                     'branch_id' => $branchId,
    //                     'purchase_bill_id' => $bill->id,
    //                     'purchase_line_id' => $purchaseLine->id,
    //                     'batch_barcode' => $batchBarcode,
    //                     'batch_no' => $batchNo,
    //                     'mrp' => $mrp,
    //                     'selling_price' => $sellingPrice,
    //                     'cost_price' => 0,
    //                     'qty' => $freeQty,
    //                     'sold_qty' => 0,
    //                     'free' => 1,
    //                     'rate' => 0,
    //                     'amount' => 0,
    //                     'expiry_date' => $line['expiry_date'] ?? null,
    //                 ]);
    //             }

    //             // ITC Entry
    //             ItcEntry::create([
    //                 'purchase_bill_id' => $bill->id,
    //                 'purchase_line_id' => $purchaseLine->id,
    //                 'product_id' => $product->id,
    //                 'gst_rate_id' => $gstRateId,
    //                 'cgst' => $cgst,
    //                 'sgst' => $sgst,
    //                 'igst' => $igst,
    //                 'total_itc' => $totalGst,
    //                 'created_by' => $user->id,
    //             ]);

    //             $totalTaxable += $taxable;
    //             $totalCgst += $cgst;
    //             $totalSgst += $sgst;
    //             $totalIgst += $igst;

    //             $processedProducts[] = $product->id;
    //         }

    //         // STEP 4: Update Bill Totals
    //         $totalTax = $totalCgst + $totalSgst + $totalIgst;

    //         $bill->update([
    //             'taxable_value' => $totalTaxable,
    //             'cgst_amount' => $totalCgst,
    //             'sgst_amount' => $totalSgst,
    //             'igst_amount' => $totalIgst,
    //             'total_tax' => $totalTax,
    //             'total_amount' => round($totalTaxable + $totalTax, 2),
    //             'received' => 1,
    //         ]);

    //         // STEP 5: Update Product Stock
    //         foreach (array_unique($processedProducts) as $pid) {
    //             $product = Product::find($pid);

    //             $totalQty = Inventory::where('product_id', $pid)->sum('qty');
    //             $totalValue = Inventory::where('product_id', $pid)
    //                 ->where('free', 0)
    //                 ->sum(DB::raw('qty * rate'));

    //             $paidQty = Inventory::where('product_id', $pid)->where('free', 0)->sum('qty');

    //             $product->stock = $totalQty;
    //             $product->cost_price = $paidQty > 0
    //                 ? round($totalValue / $paidQty, 2)
    //                 : $product->cost_price;
    //             $product->save();
    //         }

    //         DB::commit();

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Purchase bill updated successfully',
    //             'data' => $bill->load(['lines.product']),
    //         ]);
    //     } catch (\Exception $e) {
    //         DB::rollBack();

    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Error updating purchase bill',
    //             'error' => $e->getMessage(),
    //             'line' => $e->getLine(),
    //         ], 500);
    //     }
    // }

    public function update(Request $request, $id)
    {
        $request->validate([
            'supplier_id' => 'required|integer',
            'bill_no' => 'sometimes|string',
            'bill_date' => 'required|date',
            'tax_type' => 'required|in:inclusive,exclusive',
            'settlement_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'is_lost' => 'sometimes|in:0,1',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer',
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.free_qty' => 'nullable|numeric|min:0',
            'lines.*.purchase_rate' => 'required|numeric|min:0',
            'lines.*.mrp' => 'required|numeric|min:0',
            'lines.*.selling_price' => 'required|numeric|min:0',
            'lines.*.gst_rate_id' => 'required|integer',
            'lines.*.batch_no' => 'nullable|string',
            'lines.*.expiry_date' => 'nullable|date',
            'lines.*.discount' => 'nullable|numeric|min:0',
            'lines.*.discount_type' => 'nullable|in:percent,fixed',
            'lines.*.hsn_code' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $bill = PurchaseBill::with('lines.inventory')->findOrFail($id);
            $branchId = $bill->branch_id;
            $user = Auth::user();

            // STEP 1: Delete only unsold inventory items tied to this bill
            foreach ($bill->lines as $oldLine) {
                Inventory::where('purchase_line_id', $oldLine->id)
                    ->where('sold_qty', 0)
                    ->delete();
            }

            // Delete previous related line records and tax inputs
            PurchaseLine::where('purchase_bill_id', $bill->id)->delete();
            ItcEntry::where('purchase_bill_id', $bill->id)->delete();

            // STEP 2: Update bill global header variables
            $bill->update([
                'supplier_id' => $request->supplier_id,
                'bill_no' => $request->bill_no ?? $bill->bill_no,
                'bill_date' => $request->bill_date,
                'tax_type' => $request->tax_type,
                'settlement_amount' => $request->settlement_amount,
                'notes' => $request->notes,
                'is_lost' => $request->is_lost ?? 0,
                'updated_by' => $user->id,
            ]);

            // STEP 3: Parse lines & re-extract tax metrics
            $totalTaxable = $totalCgst = $totalSgst = $totalIgst = 0;
            $processedProducts = [];

            foreach ($request->lines as $line) {
                $product = Product::findOrFail($line['product_id']);

                $qty = (float) $line['qty'];
                $freeQty = (float) ($line['free_qty'] ?? 0);
                $enteredRate = (float) $line['purchase_rate']; // The rate printed on invoice paper
                $mrp = (float) $line['mrp'];
                $sellingPrice = (float) $line['selling_price'];
                $gstRateId = $line['gst_rate_id'];

                $gstRate = optional(GstRate::find($gstRateId))->rate ?? 0;
                $discountType = $line['discount_type'] ?? null;
                $discount = (float) ($line['discount'] ?? 0);

                // Calculate Base Value, Deductibles & Taxes based on Billing Mode
                if ($request->tax_type === 'inclusive') {
                    // De-escalate tax rate component back to clean item net-rate
                    $cleanRateWithoutTax = $enteredRate / (1 + ($gstRate / 100));

                    $gross = $qty * $cleanRateWithoutTax;
                    $discountAmount = $discountType === 'percent'
                        ? round($gross * $discount / 100, 4)
                        : round($discount, 4);

                    $taxable = round(max(0, $gross - $discountAmount), 2);
                    $totalTaxValue = round(($qty * $enteredRate) - ($discountType === 'percent' ? ($qty * $enteredRate * $discount / 100) : $discount), 2) - $taxable;

                    // Set adjusted standalone landing rate for accurate dynamic inventory tracking
                    $inventoryCostRate = round($taxable / $qty, 4);
                } else {
                    // Traditional exclusive transaction calculation pipeline
                    $gross = $qty * $enteredRate;
                    $discountAmount = $discountType === 'percent'
                        ? round($gross * $discount / 100, 2)
                        : round($discount, 2);

                    $taxable = round(max(0, $gross - $discountAmount), 2);
                    $totalTaxValue = round(($taxable * $gstRate) / 100, 2);

                    $inventoryCostRate = $enteredRate;
                }

                // Route CGST / SGST vs IGST structures
                $storeState = $user->store->state;
                $branchState = $user->branches->first()->state;
                $isIntra = $storeState === $branchState;

                if ($isIntra) {
                    $cgst = round($totalTaxValue / 2, 2);
                    $sgst = round($totalTaxValue / 2, 2);
                    $igst = 0;
                } else {
                    $cgst = 0;
                    $sgst = 0;
                    $igst = $totalTaxValue;
                }

                $totalGst = $cgst + $sgst + $igst;

                // Manage batches
                $hasBatch = ! empty($line['batch_no']);
                $batchNo = $hasBatch ? $line['batch_no'] : $this->generateBatchNo($product->id);

                // Recreate Purchase Line entry
                $purchaseLine = PurchaseLine::create([
                    'purchase_bill_id' => $bill->id,
                    'product_id' => $product->id,
                    'gst_rate_id' => $gstRateId,
                    'hsn_code' => $line['hsn_code'] ?? $product->hsn_code,
                    'taxable_value' => $taxable,
                    'qty' => $qty,
                    'free_qty' => $freeQty,
                    'purchase_rate' => $enteredRate, // Keep original input reference
                    'mrp' => $mrp,
                    'selling_price' => $sellingPrice,
                    'discount_type' => $discountType,
                    'discount' => $discount,
                    'amount' => $taxable,
                    'cgst' => $cgst,
                    'sgst' => $sgst,
                    'igst' => $igst,
                    'total_gst' => $totalGst,
                    'batch_no' => $batchNo,
                    'expiry_date' => $line['expiry_date'] ?? null,
                ]);

                // Setup or link Barcodes safely
                $batchBarcode = $product->barcode;
                if (Inventory::where('batch_barcode', $batchBarcode)->exists()) {
                    $batchBarcode = $product->barcode.'-'.strtoupper(Str::random(4));
                }

                // Repopulate Primary Stock Inventory
                Inventory::create([
                    'product_id' => $product->id,
                    'branch_id' => $branchId,
                    'purchase_bill_id' => $bill->id,
                    'purchase_line_id' => $purchaseLine->id,
                    'batch_barcode' => $batchBarcode,
                    'batch_no' => $batchNo,
                    'mrp' => $mrp,
                    'selling_price' => $sellingPrice,
                    'cost_price' => $inventoryCostRate,
                    'qty' => $qty,
                    'sold_qty' => 0,
                    'free' => 0,
                    'rate' => $inventoryCostRate,
                    'amount' => $taxable,
                    'expiry_date' => $line['expiry_date'] ?? null,
                ]);

                // Repopulate Free Promotional Stock Inventory
                if ($freeQty > 0) {
                    Inventory::create([
                        'product_id' => $product->id,
                        'branch_id' => $branchId,
                        'purchase_bill_id' => $bill->id,
                        'purchase_line_id' => $purchaseLine->id,
                        'batch_barcode' => $batchBarcode,
                        'batch_no' => $batchNo,
                        'mrp' => $mrp,
                        'selling_price' => $sellingPrice,
                        'cost_price' => 0,
                        'qty' => $freeQty,
                        'sold_qty' => 0,
                        'free' => 1,
                        'rate' => 0,
                        'amount' => 0,
                        'expiry_date' => $line['expiry_date'] ?? null,
                    ]);
                }

                // Document modern ITC Records
                ItcEntry::create([
                    'purchase_bill_id' => $bill->id,
                    'purchase_line_id' => $purchaseLine->id,
                    'product_id' => $product->id,
                    'gst_rate_id' => $gstRateId,
                    'cgst' => $cgst,
                    'sgst' => $sgst,
                    'igst' => $igst,
                    'total_itc' => $totalGst,
                    'created_by' => $user->id,
                ]);

                $totalTaxable += $taxable;
                $totalCgst += $cgst;
                $totalSgst += $sgst;
                $totalIgst += $igst;

                $processedProducts[] = $product->id;
            }

            // STEP 4: Resolve Invoice Accounting Totals
            $totalTax = $totalCgst + $totalSgst + $totalIgst;
            $calculatedGrandTotal = round($totalTaxable + $totalTax, 2);

            $bill->update([
                'taxable_value' => $totalTaxable,
                'cgst_amount' => $totalCgst,
                'sgst_amount' => $totalSgst,
                'igst_amount' => $totalIgst,
                'total_tax' => $totalTax,
                'total_amount' => $calculatedGrandTotal,
                'received' => 1,
            ]);

            // STEP 5: Recalculate Master Stock Metrics & Weighted Valuation Average
            foreach (array_unique($processedProducts) as $pid) {
                $product = Product::find($pid);

                $totalQty = Inventory::where('product_id', $pid)->sum('qty');
                $totalValue = Inventory::where('product_id', $pid)
                    ->where('free', 0)
                    ->sum(DB::raw('qty * rate'));

                $paidQty = Inventory::where('product_id', $pid)->where('free', 0)->sum('qty');

                $product->stock = $totalQty;
                $product->cost_price = $paidQty > 0
                    ? round($totalValue / $paidQty, 2)
                    : $product->cost_price;
                $product->save();
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Purchase bill updated successfully',
                'data' => $bill->load(['lines.product']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Error updating purchase bill',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    // public function destroy($id)
    // {
    //     try {
    //         $bill = PurchaseBill::findOrFail($id);

    //         // Delete related records
    //         PurchaseLine::where('purchase_bill_id', $id)->delete();
    //         ItcEntry::where('purchase_bill_id', $id)->delete();
    //         Inventory::where('purchase_bill_id', $id)->delete();

    //         $bill->delete();

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Purchase bill deleted successfully',
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    public function destroy(int $id)
    {
        DB::beginTransaction();

        try {
            $bill = PurchaseBill::with('lines')->findOrFail($id);
            $user = Auth::user();

            $processedProducts = $bill->lines->pluck('product_id')->unique()->toArray();

            $bill->update([
                'deleted_by' => $user->id,
            ]);

            $bill->delete();

            foreach ($processedProducts as $pid) {
                $product = Product::find($pid);
                if ($product) {
                    $totalQty = Inventory::where('product_id', $pid)->sum('qty');

                    $totalValue = Inventory::where('product_id', $pid)
                        ->where('free', 0)
                        ->sum(DB::raw('qty * rate'));

                    $paidQty = Inventory::where('product_id', $pid)
                        ->where('free', 0)
                        ->sum('qty');

                    $product->stock = $totalQty;
                    $product->cost_price = $paidQty > 0
                        ? round($totalValue / $paidQty, 2)
                        : $product->cost_price;

                    $product->save();
                }
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Purchase bill and all nested items soft-deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Error processing soft deletion sequence',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ], 500);
        }
    }
}
