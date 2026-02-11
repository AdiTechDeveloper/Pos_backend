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
use Exception;
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
                'lines.inventory'
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
                'data' => $purchaseBills
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage()
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
                'lines.inventory'
            ])->find($id);

            if (! $bill) {
                return response()->json([
                    'status' => false,
                    'message' => 'Purchase bill not found'
                ], 404);
            }

            if ($user->role === 'admin') {

                // Admin can see all bills for their store only
                if ($bill->store_id != $user->store_id) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized - Admin can only access purchase bills of their own store'
                    ], 403);
                }

                return response()->json([
                    'status' => true,
                    'data' => $bill
                ]);
            }

            if ($user->role === 'manager') {
                if ($bill->store_id != $user->store_id) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized - Manager can access only their store purchase bills'
                    ], 403);
                }

                $managerBranchIds = $user->branches()->pluck('branches.id');

                if (! $managerBranchIds->contains($bill->branch_id)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized - Manager can access purchase bills of their assigned branch only'
                    ], 403);
                }

                return response()->json([
                    'status' => true,
                    'data' => $bill
                ]);
            }

            return response()->json([
                'status' => false,
                'message' => 'Unauthorized'
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id'        => 'required|integer',
            'supplier_id'      => 'required|integer',
            'bill_no'          => [
                'required',
                'string',
                \Illuminate\Validation\Rule::unique('purchase_bills')->where(fn($q) => $q->where('supplier_id', $request->supplier_id)),
            ],
            'bill_date'              => 'required|date',
            'lines'                  => 'required|array|min:1',
            'lines.*.product_id'     => 'required|integer',
            'lines.*.qty'            => 'required|numeric|min:0.0001',
            'lines.*.free_qty'       => 'nullable|numeric|min:0',
            'lines.*.purchase_rate'  => 'required|numeric|min:0',
            'lines.*.mrp'            => 'required|numeric|min:0', // Added for Batch Pricing
            'lines.*.selling_price'  => 'required|numeric|min:0', // Added for Batch Pricing
            'lines.*.gst_rate_id'    => 'required|integer',
            'lines.*.batch_no'       => 'nullable|string',
            'lines.*.expiry_date'    => 'nullable|date',
            'lines.*.discount'       => 'nullable|numeric|min:0',
            'lines.*.discount_type'  => 'nullable|string|in:percent,fixed',
            'lines.*.is_opening'     => 'sometimes|in:0,1',
        ]);

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $storeId = $user->store_id;
            $supplier = Supplier::findOrFail($validated['supplier_id']);

            // State logic for GST
            $branch = Branch::findOrFail($validated['branch_id']);
            $originState = ($user->role === 'admin') ? Store::findOrFail($storeId)->state : $branch->state;
            $isIntra = ($originState === $supplier->state);

            $bill = PurchaseBill::create([
                'store_id'     => $storeId,
                'branch_id'    => $validated['branch_id'],
                'supplier_id'  => $validated['supplier_id'],
                'bill_no'      => $validated['bill_no'],
                'bill_date'    => $validated['bill_date'],
                'created_by'   => $user->id,
            ]);

            $totals = ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];

            foreach ($validated['lines'] as $lineData) {
                $product = Product::findOrFail($lineData['product_id']);
                $gst = GstRate::findOrFail($lineData['gst_rate_id']);

                // Calculations
                $qty = (float)$lineData['qty'];
                $freeQty = (float)($lineData['free_qty'] ?? 0);
                $purchaseRate = (float)$lineData['purchase_rate'];
                // $taxable = round(($qty * $purchaseRate) - ($lineData['discount'] ?? 0), 2);

                $discount = isset($lineData['discount']) ? (float)$lineData['discount'] : 0.0;
                $discountType = $lineData['discount_type'] ?? null;

                $grossValue = round($qty * $purchaseRate, 2);

                if ($discountType === 'percent') {
                    $discountAmount = round($grossValue * ($discount / 100), 2);
                } else {
                    $discountAmount = round($discount, 2);
                }

                $taxable = round($grossValue - $discountAmount, 2);
                if ($taxable < 0) $taxable = 0;

                // GST Logic
                $taxRate = $gst->rate;
                $cgst = $isIntra ? round(($taxable * ($taxRate / 2)) / 100, 2) : 0;
                $sgst = $isIntra ? round(($taxable * ($taxRate / 2)) / 100, 2) : 0;
                $igst = !$isIntra ? round(($taxable * $taxRate) / 100, 2) : 0;

                $line = PurchaseLine::create([
                    'purchase_bill_id'  => $bill->id,
                    'product_id'        => $product->id,
                    'qty'               => $qty,
                    'free_qty'          => $freeQty,
                    'purchase_rate'     => $purchaseRate,
                    'taxable_value'     => $taxable,
                    'cgst'              => $cgst,
                    'sgst'              => $sgst,
                    'igst'              => $igst,
                    'gst_rate_id'       => $lineData['gst_rate_id'],
                    'discount_type'    => $discountType,
                    'discount'         => $discount,
                    'hsn_code'          => $line['hsn_code'] ?? $product->hsn_code,
                    'batch_no'          => $lineData['batch_no'] ?? null,
                    'expiry_date'       => $lineData['expiry_date'] ?? null,
                ]);

                // --- BATCH BARCODE LOGIC ---
                // 1. Check if a batch with SAME prices and SAME batch_no already exists
                $existingBatch = Inventory::where('product_id', $product->id)
                    ->where('branch_id', $validated['branch_id'])
                    ->where('selling_price', $lineData['selling_price'])
                    ->where('mrp', $lineData['mrp'])
                    ->where('batch_no', $lineData['batch_no'])
                    ->where('cost_price', $purchaseRate)
                    ->where('free', 0) // Paid items only
                    ->first();

                if ($existingBatch) {
                    // Same price, same batch? Just add stock.
                    $existingBatch->increment('qty', $qty);
                    $batchBarcode = $existingBatch->batch_barcode;

                    $existingBatch->amount = $existingBatch->qty * $existingBatch->cost_price;
                    $existingBatch->save();
                } else {
                    // New Price or New Batch? Generate unique barcode
                    // If the product master barcode is already used by another batch, add suffix
                    $batchBarcode = $product->barcode;
                    if (Inventory::where('batch_barcode', $batchBarcode)->exists()) {
                        $batchBarcode = $product->barcode . '-' . strtoupper(Str::random(4));
                    }

                    $isOpening = (int)($lineData['is_opening'] ?? 0);

                    Inventory::create([
                        'product_id'       => $product->id,
                        'branch_id'        => $validated['branch_id'],
                        'purchase_bill_id' => $bill->id,
                        'purchase_line_id' => $line->id,
                        'batch_barcode'    => $batchBarcode,
                        'batch_no'         => $lineData['batch_no'],
                        'mrp'              => $lineData['mrp'],
                        'selling_price'    => $lineData['selling_price'],
                        'cost_price'       => $purchaseRate,
                        'qty'              => $qty,
                        'free'             => false,
                        'rate'             => $purchaseRate,
                        'amount'           => $qty * $purchaseRate,
                        'expiry_date'      => $lineData['expiry_date'] ?? null,
                        'is_opening'       => $isOpening,
                    ]);
                }

                // 2. Handle FREE Quantity (Separate Row per your logic)
                if ($freeQty > 0) {
                    $isOpening = (int)($lineData['is_opening'] ?? 0);

                    $existingFreeBatch = Inventory::where('product_id', $product->id)
                        ->where('branch_id', $validated['branch_id'])
                        ->where('batch_no', $lineData['batch_no'])
                        ->where('mrp', $lineData['mrp'])
                        ->where('selling_price', $lineData['selling_price'])
                        ->where('cost_price', 0)
                        ->where('free', 1)
                        ->first();

                    if ($existingFreeBatch) {
                        $existingFreeBatch->increment('qty', $freeQty);
                    } else {
                        Inventory::create([
                            'product_id'       => $product->id,
                            'branch_id'        => $validated['branch_id'],
                            'purchase_bill_id' => $bill->id,
                            'purchase_line_id' => $line->id,
                            'batch_barcode'    => $batchBarcode,
                            'batch_no'         => $lineData['batch_no'],
                            'mrp'              => $lineData['mrp'],
                            'selling_price'    => $lineData['selling_price'],
                            'cost_price'       => 0,
                            'qty'              => $freeQty,
                            'free'             => true,
                            'rate'             => 0,
                            'amount'           => 0,
                            'expiry_date'      => $lineData['expiry_date'] ?? null,
                            'is_opening'       => $isOpening,
                        ]);
                    }
                }

                // ITC Entry
                ItcEntry::create([
                    'purchase_bill_id' => $bill->id,
                    'purchase_line_id' => $line->id,
                    'product_id'       => $product->id,
                    'cgst'             => $cgst,
                    'sgst'             => $sgst,
                    'igst'             => $igst,
                    'total_itc'        => round($cgst + $sgst + $igst, 2),
                    'created_by'       => $user->id,
                ]);

                $totals['taxable'] += $taxable;
                $totals['cgst'] += $cgst;
                $totals['sgst'] += $sgst;
                $totals['igst'] += $igst;
            }

            // Update Bill Totals
            $totalTax = ($totals['cgst'] + $totals['sgst'] + $totals['igst']);
            $bill->update([
                'taxable_value' => $totals['taxable'],
                'cgst_amount'   => $totals['cgst'],
                'sgst_amount'   => $totals['sgst'],
                'igst_amount'   => $totals['igst'],
                'total_tax'     => $totalTax,
                'total_amount'  => ($totals['taxable'] + $totalTax),
                'received'      => true,
            ]);

            DB::commit();
            return response()->json(['status' => true, 'message' => 'Success', 'bill' => $bill], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'supplier_id'        => 'required|integer',
            'bill_date'          => 'required|date',
            'lines'              => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer',
            'lines.*.qty'        => 'required|numeric|min:0.0001',
            'lines.*.free_qty'   => 'nullable|numeric|min:0',
            'lines.*.purchase_rate' => 'required|numeric|min:0',
            'lines.*.gst_rate_id' => 'required|integer',
            'lines.*.batch_no'   => 'nullable|string',
            'lines.*.expiry_date' => 'nullable|date',
            'lines.*.discount'   => 'nullable|numeric|min:0',
            'lines.*.discount_type' => 'nullable|in:percent,fixed',
        ]);

        DB::beginTransaction();

        try {
            $bill = PurchaseBill::with('lines.inventory')->findOrFail($id);
            $branchId = $bill->branch_id;
            $user = Auth::user();

            // -----------------------------------------------------
            // STEP 2: Delete only unsold inventory
            // -----------------------------------------------------
            foreach ($bill->lines as $oldLine) {
                Inventory::where('purchase_line_id', $oldLine->id)
                    ->where('sold_qty', 0)
                    ->delete();
            }

            // Delete purchase lines + ITC
            PurchaseLine::where('purchase_bill_id', $bill->id)->delete();
            ItcEntry::where('purchase_bill_id', $bill->id)->delete();

            // -----------------------------------------------------
            // STEP 3: UPDATE BILL HEADER
            // -----------------------------------------------------
            $bill->update([
                'supplier_id' => $request->supplier_id,
                'bill_date'   => $request->bill_date,
                'updated_by'  => $user->id,
            ]);

            // -----------------------------------------------------
            // STEP 4: CREATE NEW LINES + INVENTORY + ITC
            // -----------------------------------------------------
            $totalTaxable = $totalCgst = $totalSgst = $totalIgst = 0;
            $processedProducts = [];

            foreach ($request->lines as $line) {

                $product = Product::findOrFail($line['product_id']);
                $qty = (float)$line['qty'];
                $freeQty = (float)($line['free_qty'] ?? 0);
                $rate = (float)$line['purchase_rate'];
                $gstRateId = $line['gst_rate_id'];

                $gstRate = optional(GstRate::find($gstRateId))->rate ?? 0;

                // Discount
                $discountType = $line['discount_type'] ?? null;
                $discount = (float)($line['discount'] ?? 0);

                $gross = $qty * $rate;

                $discountAmount = $discountType === 'percent'
                    ? round($gross * $discount / 100, 2)
                    : round($discount, 2);

                $taxable = round(max(0, $gross - $discountAmount), 2);

                // GST
                $storeState  = $user->store->state;
                $branchState = $user->branches->first()->state;
                $isIntra = $storeState === $branchState;

                if ($isIntra) {
                    $cgst = round($taxable * ($gstRate / 2) / 100, 2);
                    $sgst = round($taxable * ($gstRate / 2) / 100, 2);
                    $igst = 0;
                } else {
                    $cgst = 0;
                    $sgst = 0;
                    $igst = round(($taxable * $gstRate) / 100, 2);
                }

                $totalGst = $cgst + $sgst + $igst;

                // ------------------ Create Purchase Line ------------------
                $purchaseLine = PurchaseLine::create([
                    'purchase_bill_id' => $bill->id,
                    'product_id'       => $product->id,
                    'gst_rate_id'      => $gstRateId,
                    'hsn_code'         => $line['hsn_code'] ?? $product->hsn_code,
                    'taxable_value'   => $taxable,
                    'qty'              => $qty,
                    'free_qty'         => $freeQty,
                    'purchase_rate'    => $rate,
                    'discount_type'    => $discountType,
                    'discount'         => $discount,    // FIXED
                    'amount'           => $taxable,     // FIXED
                    'cgst'             => $cgst,
                    'sgst'             => $sgst,
                    'igst'             => $igst,
                    'total_gst'        => $totalGst,
                    'batch_no'         => $line['batch_no'] ?? null,
                    'expiry_date'      => $line['expiry_date'] ?? null,
                ]);

                // ------------------ Inventory Entry for Normal QTY ------------------
                Inventory::create([
                    'product_id'       => $product->id,
                    'branch_id'        => $branchId,
                    'purchase_bill_id' => $bill->id,
                    'purchase_line_id' => $purchaseLine->id,
                    'qty'              => $qty,
                    'sold_qty'         => 0,
                    'free'             => 0,
                    'rate'             => $rate,
                    'amount'           => $taxable,   // FIXED
                    'batch_no'         => $line['batch_no'] ?? null,
                    'expiry_date'      => $line['expiry_date'] ?? null,
                ]);

                // ------------------ FREE QTY INVENTORY ------------------
                if ($freeQty > 0) {
                    Inventory::create([
                        'product_id'       => $product->id,
                        'branch_id'        => $branchId,
                        'purchase_bill_id' => $bill->id,
                        'purchase_line_id' => $purchaseLine->id,
                        'qty'              => $freeQty,   // FIXED
                        'sold_qty'         => 0,
                        'free'             => 1,
                        'rate'             => 0,
                        'amount'           => 0,
                        'batch_no'         => $line['batch_no'] ?? null,
                        'expiry_date'      => $line['expiry_date'] ?? null,
                    ]);
                }

                // ------------------ ITC ENTRY ------------------
                ItcEntry::create([
                    'purchase_bill_id' => $bill->id,
                    'purchase_line_id' => $purchaseLine->id,
                    'product_id'       => $product->id,
                    'gst_rate_id'      => $gstRateId,
                    'cgst'             => $cgst,
                    'sgst'             => $sgst,
                    'igst'             => $igst,
                    'total_itc'        => $totalGst,
                    'created_by'       => $user->id,
                ]);

                // Accumulate totals
                $totalTaxable += $taxable;
                $totalCgst += $cgst;
                $totalSgst += $sgst;
                $totalIgst += $igst;

                $processedProducts[] = $product->id;
            }

            // -----------------------------------------------------
            // STEP 5: Update Bill Totals
            // -----------------------------------------------------
            $totalTax = $totalCgst + $totalSgst + $totalIgst;

            $bill->update([
                'taxable_value' => $totalTaxable,
                'cgst_amount'   => $totalCgst,
                'sgst_amount'   => $totalSgst,
                'igst_amount'   => $totalIgst,
                'total_tax'     => $totalTax,
                'total_amount'  => round($totalTaxable + $totalTax, 2),
                'received'      => 1,
            ]);

            // -----------------------------------------------------
            // STEP 6: UPDATE PRODUCT STOCK
            // -----------------------------------------------------
            foreach (array_unique($processedProducts) as $pid) {
                $product = Product::find($pid);

                // Calculate total stock including free
                $totalQty = Inventory::where('product_id', $pid)->sum('qty');

                // Calculate weighted average cost
                $totalValue = Inventory::where('product_id', $pid)
                    ->where('free', 0)      // only paid qty
                    ->sum(DB::raw('qty * rate'));

                $product->stock = $totalQty;
                $product->cost_price = $totalQty > 0 ? round($totalValue / max(1, $totalQty - Inventory::where('product_id', $pid)->where('free', 1)->sum('qty')), 2) : $product->cost_price;
                $product->save();
            }

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Purchase bill updated successfully',
                'data'    => $bill->load(['lines.product'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'Error updating purchase bill',
                'error'   => $e->getMessage(),
                'line'    => $e->getLine()
            ], 500);
        }
    }
}
