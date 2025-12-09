<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\ItcEntry;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PurchaseReturnController extends Controller
{
    /**
     * Store a new purchase return
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'purchase_bill_id' => ['nullable', 'integer'],
            'supplier_id' => ['required', 'integer'],
            'branch_id' => ['required', 'integer'],
            'return_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_line_id' => 'required|integer|exists:purchase_lines,id',
            'lines.*.product_id'       => 'required|integer|exists:products,id',
            'lines.*.qty'              => 'required|numeric|min:1',
            'lines.*.free'             => 'required|numeric|min:0',
            'lines.*.rate'             => 'required|numeric|min:0',
            'lines.*.gst_rate_id'      => 'required|integer',
            'lines.*.hsn_code'         => 'required|string',
            'lines.*.taxable_value'    => 'required|numeric|min:0',
            'lines.*.cgst'             => 'required|numeric|min:0',
            'lines.*.sgst'             => 'required|numeric|min:0',
            'lines.*.igst'             => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $createdBy = Auth::user();

            $purchaseReturn = PurchaseReturn::create([
                'purchase_bill_id' => $validated['purchase_bill_id'],
                'supplier_id'      => $validated['supplier_id'],
                'branch_id'        => $validated['branch_id'],
                'return_date'      => $validated['return_date'],
                'total_taxable'    => 0,
                'total_gst'        => 0,
                'total_amount'     => 0,
                'created_by'       => $createdBy->id,
            ]);

            $totalTaxable = 0;
            $totalGst = 0;
            $totalAmount = 0;

            foreach ($validated['lines'] as $line) {

                $cgstAmount = $line['cgst'];
                $sgstAmount = $line['sgst'];
                $igstAmount = $line['igst'];
                $gstTotal = $cgstAmount + $sgstAmount + $igstAmount;

                $lineTotal = $line['taxable_value'] + $gstTotal;

                $originalLine = PurchaseLine::find($line['purchase_line_id']);

                $batchNo = $line['batch_no'] ?? $originalLine->batch_no;
                $expiry  = $originalLine->expiry_date;

                $qty = $line['qty'];
                $purchaseRate = $line['rate'];
                $inventoryAmount = -round($qty * $purchaseRate, 2);

                // Insert into purchase_return_lines
                $returnLine = PurchaseReturnLine::create([
                    'purchase_return_id'  => $purchaseReturn->id,
                    'purchase_bill_line_id' => $line['purchase_line_id'],
                    'product_id'          => $line['product_id'],
                    'gst_rate_id'         => $line['gst_rate_id'],
                    'hsn_code'            => $line['hsn_code'],
                    'qty'                 => $line['qty'],
                    'free'                => $line['free'],
                    'rate'                => $line['rate'],
                    'taxable_value'       => $line['taxable_value'],
                    'cgst_amount'         => $cgstAmount,
                    'sgst_amount'         => $sgstAmount,
                    'igst_amount'         => $igstAmount,
                    'line_total'          => $lineTotal
                ]);

                // -----------------------------------------
                // REVERSE STOCK: two negative inventory rows
                // -----------------------------------------

                // 1. Negative paid qty
                Inventory::create([
                    'product_id'       => $line['product_id'],
                    'branch_id'        => $validated['branch_id'],
                    'purchase_bill_id' => $validated['purchase_bill_id'],
                    'purchase_line_id' => $line['purchase_line_id'],
                    'qty'              => -abs($line['qty']),
                    'sold_qty'         => 0,
                    'free'             => 0,
                    'batch_no'         => $batchNo,
                    'expiry_date'      => $expiry,
                    'rate'             => $line['rate'],
                    'amount'           => $inventoryAmount,
                ]);

                // 2. Negative free qty (if exists)
                if ($line['free'] > 0) {
                    Inventory::create([
                        'product_id'       => $line['product_id'],
                        'branch_id'        => $validated['branch_id'],
                        'purchase_bill_id' => $validated['purchase_bill_id'],
                        'purchase_line_id' => $line['purchase_line_id'],
                        'qty'              => -abs($line['free']),
                        'sold_qty'         => 0,
                        'free'             => true,
                        'batch_no'         => $batchNo,
                        'expiry_date'      => $expiry,
                        'rate'             => 0,
                        'amount'           => 0
                    ]);
                }

                // -----------------------------------------
                // ITC ENTRY (negative GST values)
                // -----------------------------------------
                ITCEntry::create([
                    'purchase_bill_id' => $validated['purchase_bill_id'],
                    'purchase_line_id' => $line['purchase_line_id'],
                    'product_id'       => $line['product_id'],
                    'cgst'             => -abs($cgstAmount),
                    'sgst'             => -abs($sgstAmount),
                    'igst'             => -abs($igstAmount),
                    'total_itc'        => -abs($gstTotal),
                    'created_by'       => $createdBy->id,
                ]);

                // -----------------------------------------
                // TOTALS
                // -----------------------------------------
                $totalTaxable += $line['taxable_value'];
                $totalGst += $gstTotal;
                $totalAmount += $lineTotal;
            }

            // Decrease stock in products table
            $product = Product::find($line['product_id']);

            if ($product) {
                $totalReduce = $line['qty'] + $line['free'];
                $product->decrement('stock', $totalReduce);
            }

            // Update summary totals
            $purchaseReturn->update([
                'total_taxable' => $totalTaxable,
                'total_gst'     => $totalGst,
                'total_amount'  => $totalAmount
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Purchase return created successfully",
                'data' => $purchaseReturn
            ]);
        } catch (\Exception $e) {
            dd($e->getMessage());
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

     public function index()
    {
        try {
            $user = Auth::user();

            $query = PurchaseReturn::with([
                'purchaseBill:id,bill_no',
                'store',
                'supplier',
                'branch:id,name',
                'supplier:id,name',
                'lines.product:id,name,sku',
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

            $purchaseReturns = $query
                ->orderBy('id', 'DESC')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $purchaseReturns
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}