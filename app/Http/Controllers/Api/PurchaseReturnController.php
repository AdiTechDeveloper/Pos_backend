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
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseReturnController extends Controller
{
    public function index()
    {
        try {
            $user = Auth::user();
            // Build base query
            $query = PurchaseReturn::with([
                'purchaseBill:id,bill_no',
                'supplier:id,name',
                'branch:id,name',
                'lines.product:id,name,sku',
                'lines.gstRate:id,rate',
                'lines' => function ($q) {
                    $q->select(
                        'id',
                        'purchase_return_id',
                        'product_id',
                        'gst_rate_id',
                        'hsn_code',
                        'qty',
                        'free',
                        'rate',
                        'taxable_value',
                        'cgst_amount',
                        'sgst_amount',
                        'igst_amount',
                        'line_total'
                    );
                },
            ]);

            // Restrict manager to allowed branches
            if ($user->role === 'manager') {
                $managerBranchIds = $user->branches()->pluck('branches.id');
                $query->whereIn('branch_id', $managerBranchIds);
            }

            // Restrict admin to own store branches
            if ($user->role === 'admin') {
                $query->whereHas('branch', function ($q) use ($user) {
                    $q->where('store_id', $user->store_id);
                });
            }

            $returns = $query
                ->orderBy('id', 'DESC')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $returns,
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

            // Load purchase return with relations
            $purchaseReturn = PurchaseReturn::with([
                'purchaseBill:id,bill_no',
                'branch:id,name,store_id',
                'supplier:id,name',
                'lines.product:id,name,sku',
                'lines.gstRate:id,rate',
            ])->find($id);

            if (! $purchaseReturn) {
                return response()->json([
                    'status' => false,
                    'message' => 'Purchase return not found',
                ], 404);
            }

            // Store id must be taken from the branch relation
            $returnStoreId = $purchaseReturn->branch->store_id;

            // ----------------------------------------------
            // ADMIN VALIDATION
            // ----------------------------------------------
            if ($user->role === 'admin') {
                if ($returnStoreId != $user->store_id) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized. Admin can only access purchase returns of their own store.',
                    ], 403);
                }

                return response()->json([
                    'status' => true,
                    'data' => $purchaseReturn,
                ]);
            }

            // ----------------------------------------------
            // MANAGER VALIDATION
            // ----------------------------------------------
            if ($user->role === 'manager') {

                // Check store
                if ($returnStoreId != $user->store_id) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized. Manager can only access purchase returns of their own store.',
                    ], 403);
                }

                // Check branch
                $managerBranchIds = $user->branches()->pluck('branches.id');

                if (! $managerBranchIds->contains($purchaseReturn->branch_id)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized. Manager can only access returns of assigned branches.',
                    ], 403);
                }

                return response()->json([
                    'status' => true,
                    'data' => $purchaseReturn,
                ]);
            }

            // Any other role not allowed
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a new purchase return
     */
    // public function store(Request $request)
    // {
    //     $validated = $request->validate([
    //         'purchase_bill_id' => ['required', 'integer', 'exists:purchase_bills,id'],
    //         'branch_id' => ['required', 'integer'],
    //         'supplier_id' => ['required', 'integer'],
    //         'return_date' => ['required', 'date'],
    //         'lines' => ['required', 'array', 'min:1'],
    //         'lines.*.purchase_line_id' => ['required', 'integer', 'exists:purchase_lines,id'],
    //         'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
    //         'lines.*.gst_rate_id' => ['required', 'integer', 'exists:gst_rates,id'],
    //         'lines.*.hsn_code' => ['nullable', 'string'],
    //         'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
    //         'lines.*.free_qty' => ['nullable', 'numeric', 'min:0'],
    //         'lines.*.purchase_rate' => ['required', 'numeric', 'min:0'],
    //         'lines.*.discount_type' => ['nullable', 'in:percent,fixed'],
    //         'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
    //     ]);

    //     DB::beginTransaction();

    //     try {
    //         $user = Auth::user();
    //         $supplier = Supplier::findOrFail($validated['supplier_id']);
    //         $branch = Branch::findOrFail($validated['branch_id']);

    //         // Determine intra-state or inter-state
    //         $bill = PurchaseBill::with('lines')->findOrFail($validated['purchase_bill_id']);
    //         $originState = $branch->state;
    //         $destinationState = $supplier->state;
    //         $isIntra = ($originState === $destinationState);

    //         // Create purchase return
    //         $purchaseReturn = PurchaseReturn::create([
    //             'purchase_bill_id' => $bill->id,
    //             'supplier_id' => $supplier->id,
    //             'branch_id' => $branch->id,
    //             'return_date' => $validated['return_date'],
    //             'total_taxable' => 0,
    //             'total_gst' => 0,
    //             'total_amount' => 0,
    //             'created_by' => $user->id,
    //         ]);

    //         $totalTaxable = 0;
    //         $totalCgst = 0;
    //         $totalSgst = 0;
    //         $totalIgst = 0;

    //         foreach ($validated['lines'] as $lineData) {
    //             $purchaseLine = PurchaseLine::findOrFail($lineData['purchase_line_id']);
    //             $product = Product::findOrFail($lineData['product_id']);
    //             $gst = GstRate::findOrFail($lineData['gst_rate_id']);

    //             $qty = (float) $lineData['qty'];
    //             $freeQty = (float) ($lineData['free_qty'] ?? 0);
    //             $rate = (float) $lineData['purchase_rate'];

    //             $lineDiscountType = $purchaseLine->discount_type;
    //             $lineDiscountValue = (float) $purchaseLine->discount;

    //             $gross = round($qty * $rate, 2);

    //             // ---- Discount per line ----
    //             if ($lineDiscountType === 'percent') {
    //                 $discountAmount = round($gross * ($lineDiscountValue / 100), 2);
    //             } elseif ($lineDiscountType === 'fixed' && $purchaseLine->qty > 0) {
    //                 // allocate proportionally to returned quantity
    //                 $discountAmount = round(($qty / $purchaseLine->qty) * $lineDiscountValue, 2);
    //             } else {
    //                 $discountAmount = 0.0;
    //             }

    //             $taxable = round($gross - $discountAmount, 2);
    //             $taxable = max($taxable, 0);

    //             // ---- GST ----
    //             if ($isIntra) {
    //                 $cgst = round(($taxable * ($gst->rate / 2)) / 100, 2);
    //                 $sgst = round(($taxable * ($gst->rate / 2)) / 100, 2);
    //                 $igst = 0.00;
    //             } else {
    //                 $cgst = 0.00;
    //                 $sgst = 0.00;
    //                 $igst = round(($taxable * $gst->rate) / 100, 2);
    //             }

    //             $gstTotal = $cgst + $sgst + $igst;
    //             $lineTotal = $taxable + $gstTotal;

    //             // ---- Create Purchase Return Line ----
    //             PurchaseReturnLine::create([
    //                 'purchase_return_id' => $purchaseReturn->id,
    //                 'purchase_bill_line_id' => $purchaseLine->id,
    //                 'product_id' => $product->id,
    //                 'gst_rate_id' => $gst->id,
    //                 'hsn_code' => $lineData['hsn_code'] ?? $product->hsn_code,
    //                 'qty' => $qty,
    //                 'free' => $freeQty,
    //                 'rate' => $rate,
    //                 'discount' => $discountAmount,
    //                 'taxable_value' => $taxable,
    //                 'cgst_amount' => $cgst,
    //                 'sgst_amount' => $sgst,
    //                 'igst_amount' => $igst,
    //                 'line_total' => $lineTotal,
    //             ]);

    //             // ---- Update Inventory ----
    //             Inventory::create([
    //                 'product_id' => $product->id,
    //                 'branch_id' => $branch->id,
    //                 'purchase_bill_id' => $bill->id,
    //                 'purchase_line_id' => $purchaseLine->id,
    //                 'qty' => -abs($qty),
    //                 'free' => false,
    //                 'rate' => $rate,
    //                 'amount' => -$gross,
    //                 'batch_no' => $purchaseLine->batch_no,
    //                 'expiry_date' => $purchaseLine->expiry_date,
    //             ]);

    //             if ($freeQty > 0) {
    //                 Inventory::create([
    //                     'product_id' => $product->id,
    //                     'branch_id' => $branch->id,
    //                     'purchase_bill_id' => $bill->id,
    //                     'purchase_line_id' => $purchaseLine->id,
    //                     'qty' => -abs($freeQty),
    //                     'free' => true,
    //                     'rate' => 0,
    //                     'amount' => 0,
    //                     'batch_no' => $purchaseLine->batch_no,
    //                     'expiry_date' => $purchaseLine->expiry_date,
    //                 ]);
    //             }

    //             // ---- ITC ----
    //             ItcEntry::create([
    //                 'purchase_bill_id' => $bill->id,
    //                 'purchase_line_id' => $purchaseLine->id,
    //                 'product_id' => $product->id,
    //                 'cgst' => -$cgst,
    //                 'sgst' => -$sgst,
    //                 'igst' => -$igst,
    //                 'total_itc' => -$gstTotal,
    //                 'created_by' => $user->id,
    //             ]);

    //             $product->decrement('stock', $qty + $freeQty);

    //             // ---- Update totals ----
    //             $totalTaxable += $taxable;
    //             $totalCgst += $cgst;
    //             $totalSgst += $sgst;
    //             $totalIgst += $igst;
    //         }

    //         // ---- Update purchase return totals ----
    //         $totalTaxable = round($totalTaxable, 2);
    //         $totalCgst = round($totalCgst, 2);
    //         $totalSgst = round($totalSgst, 2);
    //         $totalIgst = round($totalIgst, 2);
    //         $totalGst = $totalCgst + $totalSgst + $totalIgst;
    //         $grandTotal = $totalTaxable + $totalGst;

    //         $purchaseReturn->update([
    //             'total_taxable' => $totalTaxable,
    //             'total_gst' => $totalGst,
    //             'total_amount' => $grandTotal,
    //         ]);

    //         DB::commit();

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Purchase return created successfully',
    //             'data' => $purchaseReturn->load(['lines', 'lines.product']),
    //         ]);
    //     } catch (\Exception $e) {
    //         DB::rollBack();

    //         return response()->json([
    //             'success' => false,
    //             'message' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

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

    public function purchaseReplacement(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'purchase_bill_id' => 'required|integer|exists:purchase_bills,id',
            'branch_id' => 'nullable|integer',
            'return_date' => 'required|date',
            // The item being sent BACK to supplier
            'return_line' => 'required|array',
            'return_line.purchase_bill_line_id' => 'required|exists:purchase_lines,id',
            'return_line.qty' => 'required|numeric|min:0.0001',
            // The NEW item being received from supplier
            'new_item' => 'required|array',
            'new_item.product_id' => 'required|integer|exists:products,id',
            'new_item.qty' => 'required|numeric|min:0.0001',
            'new_item.purchase_rate' => 'required|numeric',
            'new_item.batch_no' => 'nullable|string',
            'new_item.mrp' => 'required|numeric',
            'new_item.selling_price' => 'required|numeric',
        ]);

        DB::beginTransaction();
        try {
            $bill = PurchaseBill::findOrFail($validated['purchase_bill_id']);

            $targetBranchId = $validated['branch_id'] ?? ($user->branch_id ?? $bill->branch_id);

            $oldLine = PurchaseLine::findOrFail($validated['return_line']['purchase_bill_line_id']);
            $returnQty = $validated['return_line']['qty'];

            // Decrement existing batch
            $inventoryOut = Inventory::where('purchase_line_id', $oldLine->id)
                ->where('branch_id', $validated['branch_id'])
                ->first();

            if (! $inventoryOut || $inventoryOut->qty < $returnQty) {
                throw new \Exception('Not enough stock in original batch to replace.');
            }
            $inventoryOut->decrement('qty', $returnQty);

            // Record the Return
            $purchaseReturn = PurchaseReturn::create([
                'purchase_bill_id' => $bill->id,
                'supplier_id' => $bill->supplier_id,
                'return_type' => 'replacement',
                'branch_id' => $targetBranchId,
                'return_date' => $validated['return_date'],
                'total_amount' => 0, // Will update later
                'created_by' => $user->id,
            ]);

            $newItem = $validated['new_item'];
            $batchNo = $newItem['batch_no'] ?? $this->generateBatchNo($newItem['product_id']);

            // Check if this batch exists or create new (Same logic as your Purchase Store)
            $inventoryIn = Inventory::create([
                'product_id' => $newItem['product_id'],
                'branch_id' => $targetBranchId,
                'purchase_bill_id' => $bill->id,
                'batch_no' => $batchNo,
                'qty' => $newItem['qty'],
                'cost_price' => $newItem['purchase_rate'],
                'mrp' => $newItem['mrp'],
                'selling_price' => $newItem['selling_price'],
                'amount' => $newItem['qty'] * $newItem['purchase_rate'],
                // Generate barcode or use product barcode
                'batch_barcode' => $oldLine->product->barcode.'-'.Str::random(4),
            ]);

            // Calculate Value of Old vs New
            $oldValue = ($returnQty * $oldLine->purchase_rate);
            $newValue = ($newItem['qty'] * $newItem['purchase_rate']);
            $diff = $newValue - $oldValue;

            // If you implemented the Supplier Balance from earlier:
            // $supplier = Supplier::find($bill->supplier_id);
            // $supplier->increment('current_balance', $diff);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Replacement Successful',
                'branch_id' => $targetBranchId,
                'diff' => $diff,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function purchaseReturn(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'purchase_bill_id' => 'required|integer|exists:purchase_bills,id',
            'branch_id' => 'nullable|integer',
            'supplier_id' => 'required|integer',
            'return_date' => 'required|date',
            'lines' => 'required|array|min:1',
            'lines.*.purchase_bill_line_id' => 'required|integer|exists:purchase_lines,id',
            'lines.*.qty' => 'required|numeric|min:0.0001',
        ]);

        DB::beginTransaction();
        try {
            $bill = PurchaseBill::findOrFail($validated['purchase_bill_id']);
            $targetBranchId = $validated['branch_id'] ?? ($user->branch_id ?? $bill->branch_id);

            // Create the Main Return Header
            $purchaseReturn = PurchaseReturn::create([
                'purchase_bill_id' => $validated['purchase_bill_id'],
                'supplier_id' => $validated['supplier_id'],
                'return_type' => 'return',
                'branch_id' => $targetBranchId,
                'return_date' => $validated['return_date'],
                'created_by' => $user->id,
                'total_taxable' => 0, // Will update after loop
                'total_gst' => 0,
                'total_amount' => 0,
            ]);

            $totals = ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];

            foreach ($validated['lines'] as $lineData) {
                $purchaseLine = PurchaseLine::findOrFail($lineData['purchase_bill_line_id']);
                $returnQty = (float) $lineData['qty'];

                /** Pro-rata Calculations (Matching your Purchase store logic)
                 * We calculate values based on the original line's purchase rate
                 */
                $ratio = $returnQty / $purchaseLine->qty;

                $taxableValue = round($purchaseLine->taxable_value * $ratio, 2);
                $cgst = round($purchaseLine->cgst * $ratio, 2);
                $sgst = round($purchaseLine->sgst * $ratio, 2);
                $igst = round($purchaseLine->igst * $ratio, 2);
                $lineTotal = $taxableValue + $cgst + $sgst + $igst;

                // Create Return Line
                PurchaseReturnLine::create([
                    'purchase_return_id' => $purchaseReturn->id,
                    'purchase_bill_line_id' => $purchaseLine->id,
                    'product_id' => $purchaseLine->product_id,
                    'qty' => $returnQty,
                    'rate' => $purchaseLine->purchase_rate,
                    'gst_rate_id' => $purchaseLine->gst_rate_id,
                    'hsn_code' => $purchaseLine->hsn_code,
                    'taxable_value' => $taxableValue,
                    'cgst_amount' => $cgst,
                    'sgst_amount' => $sgst,
                    'igst_amount' => $igst,
                    'line_total' => $lineTotal,
                ]);

                // Update Inventory (Decrement)
                $inventory = Inventory::where('purchase_line_id', $purchaseLine->id)
                    ->where('branch_id', $targetBranchId)
                    ->first();

                if (! $inventory || $inventory->qty < $returnQty) {
                    throw new \Exception("Insufficient stock in batch for product ID: {$purchaseLine->product_id}");
                }

                $inventory->decrement('qty', $returnQty);
                $inventory->update(['amount' => $inventory->qty * $inventory->cost_price]);

                // Accumulate totals
                $totals['taxable'] += $taxableValue;
                $totals['cgst'] += $cgst;
                $totals['sgst'] += $sgst;
                $totals['igst'] += $igst;
            }

            // Update Main Return Totals
            $totalGst = $totals['cgst'] + $totals['sgst'] + $totals['igst'];
            $purchaseReturn->update([
                'total_taxable' => $totals['taxable'],
                'total_gst' => $totalGst,
                'total_amount' => ($totals['taxable'] + $totalGst),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Return Processed Successfully',
                'branch_id' => $targetBranchId,
                'data' => $purchaseReturn,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
