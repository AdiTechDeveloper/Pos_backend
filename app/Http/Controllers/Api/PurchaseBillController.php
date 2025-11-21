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
                'branch:id,name',
                'supplier:id,name',
                'lines.product:id,name,sku',
                'lines.inventory'
            ]);

            if ($user->role === 'manager') {
                $query->where('branch_id', $user->branch_id);
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

                // Manager must belong to same store AND same branch
                if ($bill->store_id != $user->store_id || $bill->branch_id != $user->branch_id) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized - Manager can access purchase bills of their own branch only'
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
            'bill_no'          => 'required|string',
            'bill_date'        => 'required|date',

            'lines'                    => 'required|array',
            'lines.*.product_id'       => 'required|integer',
            'lines.*.qty'              => 'required|numeric',
            'lines.*.free_qty'         => 'nullable|numeric',
            'lines.*.purchase_rate'    => 'required|numeric',
            'lines.*.discount_type'    => 'nullable|string',
            'lines.*.hsn_code'         => 'nullable|string',
            'lines.*.discount'         => 'nullable|numeric',
            'lines.*.gst_rate_id'      => 'required|integer',
            'lines.*.batch_no'         => 'nullable|string',
            'lines.*.expiry_date'      => 'nullable|date',
        ]);

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $storeId = $user->store_id;

            $supplier = Supplier::findOrFail($validated['supplier_id']);

            if ($user->role === 'admin') {
                $originState = Store::findOrFail($storeId)->state;
            } else {
                $originState = Branch::findOrFail($validated['branch_id'])->state;
            }

            $destinationState = $supplier->state;
            $isIntra = $originState === $destinationState;

            // Create bill
            $bill = PurchaseBill::create([
                'store_id'     => $storeId,
                'branch_id'    => $validated['branch_id'],
                'supplier_id'  => $validated['supplier_id'],
                'bill_no'      => $validated['bill_no'],
                'bill_date'    => $validated['bill_date'],
                'created_by'   => $user->id,
            ]);

            $totalTaxable = 0;
            $totalCgst = 0;
            $totalSgst = 0;
            $totalIgst = 0;

            foreach ($validated['lines'] as $lineData) {

                $product = Product::findOrFail($lineData['product_id']);
                $gst = GstRate::findOrFail($lineData['gst_rate_id']);

                $qty = $lineData['qty'];
                $freeQty = $lineData['free_qty'] ?? 0;
                $rate = $lineData['purchase_rate'];
                $discount = $lineData['discount'] ?? 0;

                // ----- DISCOUNT -----
                $gross = $qty * $rate;

                if (($lineData['discount_type'] ?? null) === 'percent') {
                    $discountAmount = $gross * ($discount / 100);
                } else {
                    $discountAmount = $discount;
                }

                $taxable = $gross - $discountAmount;

                // ----- GST CALC -----
                if ($isIntra) {
                    $cgst = ($taxable * ($gst->rate / 2)) / 100;
                    $sgst = ($taxable * ($gst->rate / 2)) / 100;
                    $igst = 0;
                } else {
                    $cgst = 0;
                    $sgst = 0;
                    $igst = ($taxable * $gst->rate) / 100;
                }

                // ----- INSERT PURCHASE LINE -----
                $line = PurchaseLine::create([
                    'purchase_bill_id' => $bill->id,
                    'product_id'       => $product->id,
                    'gst_rate_id'      => $gst->id,
                    'qty'              => $qty,
                    'free_qty'         => $freeQty,
                    'purchase_rate'    => $rate,
                    'hsn_code'         => $lineData['hsn_code'] ?? null,
                    'discount_type'    => $lineData['discount_type'] ?? null,
                    'discount'         => $discount,
                    'batch_no'         => $lineData['batch_no'] ?? null,
                    'expiry_date'      => $lineData['expiry_date'] ?? null,

                    'taxable_value'    => $taxable,
                    'cgst'             => $cgst,
                    'sgst'             => $sgst,
                    'igst'             => $igst,
                ]);

                // ----- INVENTORY -----

                // Normal purchased qty
                Inventory::create([
                    'product_id'       => $product->id,
                    'branch_id'        => $validated['branch_id'],
                    'purchase_bill_id' => $bill->id,
                    'purchase_line_id' => $line->id,
                    'qty'              => $qty,
                    'free'             => false,
                    'rate'             => $rate,
                    'amount'           => $qty * $rate,
                    'batch_no'         => $lineData['batch_no'] ?? null,
                    'expiry_date'      => $lineData['expiry_date'] ?? null,
                ]);

                // Free qty
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

                // ----- WEIGHTED AVERAGE COST -----
                $oldStockValue = $product->stock * $product->cost_price;
                $newStockValue = ($qty + $freeQty) * $rate;

                $newTotalQty = $product->stock + $qty + $freeQty;

                if ($newTotalQty > 0) {
                    $product->cost_price = ($oldStockValue + $newStockValue) / $newTotalQty;
                } else {
                    $product->cost_price = $rate;
                }

                $product->stock = $newTotalQty;
                $product->save();

                // ----- ITC ENTRY -----
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

                // Update totals
                $totalTaxable += $taxable;
                $totalCgst += $cgst;
                $totalSgst += $sgst;
                $totalIgst += $igst;
            }

            // ----- UPDATE BILL TOTALS -----
            $bill->update([
                'taxable_value' => $totalTaxable,
                'cgst_amount'   => $totalCgst,
                'sgst_amount'   => $totalSgst,
                'igst_amount'   => $totalIgst,
                'total_tax'     => $totalCgst + $totalSgst + $totalIgst,
                'total_amount'  => $totalTaxable + $totalCgst + $totalSgst + $totalIgst,
                'received'      => true,
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Purchase bill created successfully',
                'data' => $bill->load('lines')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while creating the purchase bill.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
}
