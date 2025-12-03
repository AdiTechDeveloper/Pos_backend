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
use Illuminate\Validation\Rule;  

class PurchaseBillController extends Controller
{
    public function index()
    {
        try {
            $user = Auth::user();

            $query = PurchaseBill::with([
                'store', 'supplier',
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
                'data' => $bill->load(['lines', 'lines.product'])
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
        $validated = $request->validate([
            'branch_id'        => 'required|integer',
            'supplier_id'      => 'required|integer',

            // bill no must be unique per supplier, but ignore current bill
            'bill_no'          => [
                'required',
                'string',
                Rule::unique('purchase_bills')->ignore($id)
                    ->where(fn($q) => $q->where('supplier_id', $request->supplier_id)),
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

            $bill = PurchaseBill::with(['lines', 'lines.product'])->findOrFail($id);

            $supplier = Supplier::findOrFail($validated['supplier_id']);

            // Origin state
            $originState = $user->role === 'admin'
                ? Store::findOrFail($storeId)->state
                : Branch::findOrFail($validated['branch_id'])->state;

            $isIntra = $originState === $supplier->state;

            /*------------------------------------------------------
            | RESTORE PREVIOUS STOCK BEFORE UPDATING
            ------------------------------------------------------*/
            foreach ($bill->lines as $oldLine) {
                $product = $oldLine->product;

                $oldTotalUnits = $oldLine->qty + $oldLine->free_qty;

                // reduce stock
                $product->stock -= $oldTotalUnits;
                if ($product->stock < 0) $product->stock = 0;  // safety

                $product->save();
            }

            // delete old lines, inventories, ITC
            PurchaseLine::where('purchase_bill_id', $bill->id)->delete();
            Inventory::where('purchase_bill_id', $bill->id)->delete();
            ItcEntry::where('purchase_bill_id', $bill->id)->delete();

            /*------------------------------------------------------
            | UPDATE BILL BASIC DETAILS
            ------------------------------------------------------*/
            $bill->update([
                'branch_id'    => $validated['branch_id'],
                'supplier_id'  => $validated['supplier_id'],
                'bill_no'      => $validated['bill_no'],
                'bill_date'    => $validated['bill_date'],
                'updated_by'   => $user->id,
            ]);

            $totalTaxable = $totalCgst = $totalSgst = $totalIgst = 0;

            /*------------------------------------------------------
            | NEW LINES PROCESSING
            ------------------------------------------------------*/
            foreach ($validated['lines'] as $lineData) {

                $product = Product::findOrFail($lineData['product_id']);
                $gst = GstRate::findOrFail($lineData['gst_rate_id']);

                $qty = (float)$lineData['qty'];
                $freeQty = (float)($lineData['free_qty'] ?? 0);
                $purchaseRate = (float)$lineData['purchase_rate'];

                $discount = (float)($lineData['discount'] ?? 0);
                $discountType = $lineData['discount_type'] ?? null;

                $hsn = $lineData['hsn_code'] ?? $product->hsn_code;

                // GROSS
                $gross = round($qty * $purchaseRate, 2);

                // DISCOUNT
                $discountAmount = $discountType === 'percent'
                    ? round($gross * ($discount / 100), 2)
                    : round($discount, 2);

                $taxable = max(0, round($gross - $discountAmount, 2));

                // GST
                if ($isIntra) {
                    $cgst = round(($taxable * ($gst->rate / 2)) / 100, 2);
                    $sgst = round(($taxable * ($gst->rate / 2)) / 100, 2);
                    $igst = 0;
                } else {
                    $cgst = 0;
                    $sgst = 0;
                    $igst = round(($taxable * $gst->rate) / 100, 2);
                }

                // Create line
                $line = PurchaseLine::create([
                    'purchase_bill_id' => $bill->id,
                    'product_id'       => $product->id,
                    'gst_rate_id'      => $gst->id,
                    'qty'              => $qty,
                    'free_qty'         => $freeQty,
                    'purchase_rate'    => $purchaseRate,
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

                /*------------------------------------------------------
                | INVENTORY UPDATE
                ------------------------------------------------------*/
                Inventory::create([
                    'product_id'       => $product->id,
                    'branch_id'        => $validated['branch_id'],
                    'purchase_bill_id' => $bill->id,
                    'purchase_line_id' => $line->id,
                    'qty'              => $qty,
                    'free'             => false,
                    'rate'             => $purchaseRate,
                    'amount'           => round($qty * $purchaseRate, 2),
                    'batch_no'         => $lineData['batch_no'] ?? null,
                    'expiry_date'      => $lineData['expiry_date'] ?? null,
                ]);

                if ($freeQty > 0) {
                    Inventory::create([
                        'product_id'       => $product->id,
                        'branch_id'        => $validated['branch_id'],
                        'purchase_bill_id' => $bill->id,
                        'purchase_line_id' => $line->id,
                        'qty'              => $freeQty,
                        'free'             => true,
                        'rate'             => 0,
                        'amount'           => 0,
                        'batch_no'         => $lineData['batch_no'] ?? null,
                        'expiry_date'      => $lineData['expiry_date'] ?? null,
                    ]);
                }

                /*------------------------------------------------------
                | STOCK & COST PRICE UPDATE
                ------------------------------------------------------*/
                $totalUnits = $qty + $freeQty;

                // Update stock
                $product->stock += $totalUnits;

                // Weighted average cost (paid units only)
                $effectiveRate = ($qty * $purchaseRate) / ($totalUnits ?: 1);

                $existingValue = $product->stock * $product->cost_price;
                $newValue = $totalUnits * $effectiveRate;

                $totalQty = $product->stock + $totalUnits;

                $product->cost_price = round(($existingValue + $newValue) / max(1, $product->stock), 2);
                $product->save();

                /*------------------------------------------------------
                | ITC ENTRY
                ------------------------------------------------------*/
                ItcEntry::create([
                    'purchase_bill_id' => $bill->id,
                    'purchase_line_id' => $line->id,
                    'product_id'       => $product->id,
                    'cgst'             => $cgst,
                    'sgst'             => $sgst,
                    'igst'             => $igst,
                    'total_itc'        => $cgst + $sgst + $igst,
                    'created_by'       => $user->id,
                ]);

                $totalTaxable += $taxable;
                $totalCgst += $cgst;
                $totalSgst += $sgst;
                $totalIgst += $igst;
            }

            /*------------------------------------------------------
            | UPDATE TOTALS
            ------------------------------------------------------*/
            $totalTax = round($totalCgst + $totalSgst + $totalIgst, 2);
            $grandTotal = round($totalTaxable + $totalTax, 2);

            $bill->update([
                'taxable_value' => $totalTaxable,
                'cgst_amount'   => $totalCgst,
                'sgst_amount'   => $totalSgst,
                'igst_amount'   => $totalIgst,
                'total_tax'     => $totalTax,
                'total_amount'  => $grandTotal,
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Purchase bill updated successfully',
                'data' => $bill->load(['lines', 'lines.product'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong.',
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

            $purchase_bill = PurchaseBill::where('id', $id)->first();

            if (!$purchase_bill) {
                return response()->json([
                    'status' => false,
                    'message' => 'Purchase Bill not found'
                ], 404);
            }

            $purchase_bill->delete();

            return response()->json([
                'status' => true,
                'message' => 'Purchase Bill deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while deleting the product.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    }