<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GstOutputLedger;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\SalesBill;
use App\Models\SalesBillLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SalesBillController extends Controller
{
    public function scanBarcode(Request $request)
    {
        $request->validate([
            'barcode' => 'required'
        ]);

        $user = Auth::user();

        $branchIds = $user->branches->pluck('id')->toArray();

        $product = Product::with('gstRate', 'inventories')
            ->where('barcode', $request->barcode)
            ->whereHas('inventories', function ($q) use ($branchIds) {
                $q->whereIn('branch_id', $branchIds);
            })
            ->first();

        if (! $product) {
            return response()->json(['status' => false, 'message' => 'Product not found'], 404);
        }

        return response()->json(['status' => true, 'data' => $product]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required',
            'lines.*.qty' => 'required|numeric|min:1',
        ]);

        try {
            $user = Auth::user();

            $branchId = $user->branches->pluck('id')->first();

            $bill = SalesBill::create([
                'store_id'  => $user->store_id,
                'branch_id' => $branchId,
                'user_id'   => $user->id,
                'bill_no'   => 'SB-' . time()
            ]);

            $subtotal = 0;
            $totalGst = 0;

            foreach ($request->lines as $line) {
                $product = Product::with('gstRate')->findOrFail($line['product_id']);

                // FIND INVENTORY FIFO
                $inventory = Inventory::where('product_id', $product->id)
                    ->where('branch_id', $branchId)
                    ->orderBy('expiry_date')
                    ->first();

                if (! $inventory || $inventory->qty < $line['qty']) {
                    return response()->json(['status' => false, 'message' => 'Insufficient stock'], 400);
                }

                $gstRate = $product->gstRate ? $product->gstRate->rate : 0;
                $half = $gstRate / 2;

                $branch = $user->branches->first();

                $branchState = $branch->state;
                $storeState  = $user->store->state;

                if ($gstRate == 0) {
                    $cgst = $sgst = $igst = 0;
                } else {
                    if ($storeState == $branchState) {
                        // INTRA
                        $cgst = ($product->selling_price * $half / 100) * $line['qty'];
                        $sgst = ($product->selling_price * $half / 100) * $line['qty'];
                        $igst = 0;
                    } else {
                        // INTER
                        $cgst = 0;
                        $sgst = 0;
                        $igst = ($product->selling_price * $gstRate / 100) * $line['qty'];
                    }
                }

                $amount = $line['qty'] * $product->selling_price;
                $totalLineGst = $cgst + $sgst + $igst;

                // CREATE LINE
                $salesLine = SalesBillLine::create([
                    'sales_bill_id' => $bill->id,
                    'product_id'    => $product->id,
                    'branch_id'     => $branchId,
                    'inventory_id'  => $inventory->id,
                    'qty'           => $line['qty'],
                    'rate'          => $product->selling_price,
                    'amount'        => $amount,
                    'cgst'          => $cgst,
                    'sgst'          => $sgst,
                    'igst'          => $igst,
                    'total_gst'     => $totalLineGst
                ]);

                // CREATE OUTPUT GST
                GstOutputLedger::create([
                    'sales_bill_id'      => $bill->id,
                    'sales_bill_line_id' => $salesLine->id,
                    'product_id'         => $product->id,
                    'gst_rate_id'        => $product->gst_rate_id,
                    'cgst'               => $cgst,
                    'sgst'               => $sgst,
                    'igst'               => $igst,
                    'total_gst'          => $totalLineGst,
                ]);

                // UPDATE INVENTORY
                $inventory->qty -= $line['qty'];
                $inventory->save();

                $subtotal += $amount;
                $totalGst += $totalLineGst;
            }

            // FINAL BILL UPDATE
            $bill->update([
                'subtotal'     => $subtotal,
                'total_gst'    => $totalGst,
                'total_amount' => $subtotal + $totalGst
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Sales bill created successfully',
                'data' => $bill->load('lines')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while creating the sales bill.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function gstReport()
    {
        try {
            $user = Auth::user();

            $query = GstOutputLedger::with('bill');

            $branchId = $user->branches->pluck('id')->first();

            if ($user->role === 'manager') {
                $query->whereHas(
                    'bill',
                    fn($q) =>
                    $q->where('branch_id', $branchId)
                );
            }

            if ($user->role === 'admin') {
                $query->whereHas(
                    'bill',
                    fn($q) =>
                    $q->where('store_id', $user->store_id)
                );
            }

            $data = $query->orderBy('id', 'DESC')->get();

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
