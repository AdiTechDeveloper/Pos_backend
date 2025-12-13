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
            // ensure bill_no unique per supplier
            'bill_no'          => [
                'required',
                'string',
                \Illuminate\Validation\Rule::unique('purchase_bills')->where(function ($query) use ($request) {
                    return $query->where('supplier_id', $request->supplier_id);
                }),
            ],
            'bill_date'        => 'required|date',

            'lines'                    => 'required|array|min:1',
            'lines.*.product_id'       => 'required|integer',
            'lines.*.qty'              => 'required|numeric|min:0.0001',
            'lines.*.free_qty'         => 'nullable|numeric|min:0',
            'lines.*.purchase_rate'    => 'required|numeric|min:0',
            'lines.*.discount_type'    => 'nullable|in:percent,fixed',
            'lines.*.hsn_code'         => 'nullable|string',
            'lines.*.discount'         => 'nullable|numeric|min:0',
            'lines.*.gst_rate_id'      => 'required|integer',
            'lines.*.batch_no'         => 'nullable|string',
            'lines.*.expiry_date'      => 'nullable|date',
        ]);

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $storeId = $user->store_id;

            $supplier = Supplier::findOrFail($validated['supplier_id']);

            // origin state: for admin use store state, otherwise branch state
            if ($user->role === 'admin') {
                $originState = Store::findOrFail($storeId)->state;
            } else {
                $originState = Branch::findOrFail($validated['branch_id'])->state;
            }

            $destinationState = $supplier->state;
            $isIntra = ($originState === $destinationState);

            // Create purchase bill
            $bill = PurchaseBill::create([
                'store_id'     => $storeId,
                'branch_id'    => $validated['branch_id'],
                'supplier_id'  => $validated['supplier_id'],
                'bill_no'      => $validated['bill_no'],
                'bill_date'    => $validated['bill_date'],
                'created_by'   => $user->id,
            ]);

            $totalTaxable = 0.0;
            $totalCgst = 0.0;
            $totalSgst = 0.0;
            $totalIgst = 0.0;

            foreach ($validated['lines'] as $lineData) {

                $product = Product::findOrFail($lineData['product_id']);
                $gst = GstRate::findOrFail($lineData['gst_rate_id']);

                // quantities and rates
                $qty = (float) $lineData['qty'];
                $freeQty = isset($lineData['free_qty']) ? (float)$lineData['free_qty'] : 0.0;
                $purchaseRate = (float) $lineData['purchase_rate'];
                $discount = isset($lineData['discount']) ? (float)$lineData['discount'] : 0.0;
                $discountType = $lineData['discount_type'] ?? null;

                // HSN fallback
                $hsn = $lineData['hsn_code'] ?? $product->hsn_code;

                // ----- GROSS and DISCOUNT -----
                $grossValue = round($qty * $purchaseRate, 2);

                if ($discountType === 'percent') {
                    $discountAmount = round($grossValue * ($discount / 100), 2);
                } else {
                    // treat null or fixed as fixed amount
                    $discountAmount = round($discount, 2);
                }

                $taxable = round($grossValue - $discountAmount, 2);
                if ($taxable < 0) {
                    $taxable = 0.00;
                }

                // ----- GST CALCULATION (rounded) -----
                if ($isIntra) {
                    $cgst = round(($taxable * ($gst->rate / 2)) / 100, 2);
                    $sgst = round(($taxable * ($gst->rate / 2)) / 100, 2);
                    $igst = 0.00;
                } else {
                    $cgst = 0.00;
                    $sgst = 0.00;
                    $igst = round(($taxable * $gst->rate) / 100, 2);
                }

                // ----- INSERT PURCHASE LINE -----
                $line = PurchaseLine::create([
                    'purchase_bill_id' => $bill->id,
                    'product_id'       => $product->id,
                    'gst_rate_id'      => $gst->id,
                    'qty'              => $qty,
                    'free_qty'         => $freeQty,
                    'purchase_rate'    => round($purchaseRate, 2),
                    'hsn_code'         => $hsn,
                    'discount_type'    => $discountType,
                    'discount'         => $discount,
                    'batch_no'         => $lineData['batch_no'] ?? null,
                    'expiry_date'      => $lineData['expiry_date'] ?? null,

                    'taxable_value'    => $taxable,
                    'cgst'             => $cgst,
                    'sgst'             => $sgst,
                    'igst'             => $igst,
                ]);

                // ----- INVENTORY RECORDS -----
                // Create inventory for purchased qty (non-free)
                Inventory::create([
                    'product_id'       => $product->id,
                    'branch_id'        => $validated['branch_id'],
                    'purchase_bill_id' => $bill->id,
                    'purchase_line_id' => $line->id,
                    'qty'              => $qty,
                    'free'             => false,
                    'rate'             => round($purchaseRate, 2),
                    'amount'           => round($qty * $purchaseRate, 2),
                    'batch_no'         => $lineData['batch_no'] ?? null,
                    'expiry_date'      => $lineData['expiry_date'] ?? null,
                ]);

                // Create inventory for free qty (rate = 0, amount = 0)
                if ($freeQty > 0) {
                    Inventory::create([
                        'product_id'       => $product->id,
                        'branch_id'        => $validated['branch_id'],
                        'purchase_bill_id' => $bill->id,
                        'purchase_line_id' => $line->id,
                        'qty'              => $freeQty,
                        'sold_qty'         => 0,
                        'free'             => true,
                        'rate'             => 0.00,
                        'amount'           => 0.00,
                        'batch_no'         => $lineData['batch_no'] ?? null,
                        'expiry_date'      => $lineData['expiry_date'] ?? null,
                    ]);
                }

                // ----- EFFECTIVE RATE (accounting for free items) -----
                // If supplier gave free items, effective rate per unit reduces
                $totalReceivedUnits = $qty + $freeQty;
                if ($totalReceivedUnits > 0) {
                    // total paid for goods = qty * purchaseRate (free items are not paid)
                    $effectiveRate = round(($qty * $purchaseRate) / $totalReceivedUnits, 4); // keep more precision for avg calc
                } else {
                    $effectiveRate = round($purchaseRate, 4);
                }

                // ----- WEIGHTED AVERAGE COST UPDATE -----
                // compute existing stock value (use cost_price and current stock)
                $existingStockQty = (float) $product->stock;
                $existingCostPrice = (float) $product->cost_price;

                $oldStockValue = round($existingStockQty * $existingCostPrice, 4);
                $newStockValue = round($totalReceivedUnits * $effectiveRate, 4);

                $newTotalQty = $existingStockQty + $totalReceivedUnits;

                if ($newTotalQty > 0) {
                    $newCostPrice = ($oldStockValue + $newStockValue) / $newTotalQty;
                } else {
                    $newCostPrice = $effectiveRate;
                }

                // rounding cost price to 2 decimals for storage
                $product->cost_price = round($newCostPrice, 2);
                $product->stock = $newTotalQty;
                $product->save();

                // ----- ITC ENTRY (only on charged taxable value) -----
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

                // ----- UPDATE RUNNING TOTALS -----
                $totalTaxable += $taxable;
                $totalCgst += $cgst;
                $totalSgst += $sgst;
                $totalIgst += $igst;
            }

            // Round totals before saving
            $totalTaxable = round($totalTaxable, 2);
            $totalCgst = round($totalCgst, 2);
            $totalSgst = round($totalSgst, 2);
            $totalIgst = round($totalIgst, 2);
            $totalTax = round($totalCgst + $totalSgst + $totalIgst, 2);
            $grandTotal = round($totalTaxable + $totalTax, 2);

            // ----- UPDATE BILL TOTALS -----
            $bill->update([
                'taxable_value' => $totalTaxable,
                'cgst_amount'   => $totalCgst,
                'sgst_amount'   => $totalSgst,
                'igst_amount'   => $totalIgst,
                'total_tax'     => $totalTax,
                'total_amount'  => $grandTotal,
                'received'      => true,
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Purchase bill created successfully',
                'data' => $bill->load(['lines', 'lines.product', 'lines.inventory'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while creating the purchase bill.',
                'error' => $e->getMessage()
            ], 500);
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
            // STEP 1: Prevent update if any sold quantity exists
            // -----------------------------------------------------
            // foreach ($bill->lines as $oldLine) {
            //     if (Inventory::where('purchase_line_id', $oldLine->id)->where('sold_qty', '>', 0)->exists()) {
            //         throw new Exception("Cannot edit purchase bill. Items already sold.");
            //     }
            // }

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
            // dd( $e->getMessage());
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
