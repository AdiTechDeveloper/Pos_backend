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
use Illuminate\Support\Facades\DB;
use Milon\Barcode\Facades\DNS1DFacade as DNS1D;

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
            'lines.*.product_id' => 'required|integer',
            'lines.*.qty' => 'required|numeric|min:1',
        ]);

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $branchId = $user->branches->pluck('id')->first();

            if (!$branchId) {
                return response()->json([
                    'status' => false,
                    'message' => "User has no branch assigned."
                ], 400);
            }

            $storeId   = str_pad($user->store_id, 2, '0', STR_PAD_LEFT);
            $brId      = str_pad($branchId, 2, '0', STR_PAD_LEFT);
            $counterId = str_pad($user->id, 2, '0', STR_PAD_LEFT);
            $date      = now()->format('ymd'); // YYMMDD

            $todayCount = SalesBill::where('store_id', $user->store_id)
                ->where('branch_id', $branchId)
                ->whereDate('created_at', today())
                ->count();

            $seq = str_pad($todayCount + 1, 4, '0', STR_PAD_LEFT);

            $billNo = "{$storeId}{$brId}{$counterId}{$date}{$seq}";

            // CREATE BILL HEADER
            $bill = SalesBill::create([
                'store_id'      => $user->store_id,
                'branch_id'     => $branchId,
                'user_id'       => $user->id,
                'bill_no'       => $billNo,
                'created_by'    => $user->id,
            ]);

            $subtotal = 0;
            $totalGst = 0;
            $totalSaved = 0;
            $totalCogs = 0;
            $totalProfit = 0;

            foreach ($request->lines as $line) {

                $product = Product::with('gstRate')->findOrFail($line['product_id']);

                if ($product->selling_price <= 0) {
                    return response()->json([
                        'status' => false,
                        'message' => "Invalid selling price for {$product->name}"
                    ], 400);
                }

                // ----------------------------------------------------
                // 1️⃣ FIFO INVENTORY (NOT BY expiry, but true FIFO)
                // ----------------------------------------------------
                $requiredQty = $line['qty'];

                $fifoBatches = Inventory::where('product_id', $product->id)
                    ->where('branch_id', $branchId)
                    ->where('qty', '>', 0)
                    ->orderBy('id') // FIFO
                    ->lockForUpdate()
                    ->get();

                if ($fifoBatches->sum('qty') < $line['qty']) {
                    return response()->json([
                        'status' => false,
                        'message' => "Insufficient stock for {$product->name}"
                    ], 400);
                }

                // COGS Calculation: total cost of consumed batches
                $remaining = $requiredQty;
                $totalLineCogs = 0;
                $firstInventoryId = null;

                foreach ($fifoBatches as $batch) {
                    if ($remaining <= 0) break;

                    $consume = min($batch->qty, $remaining);

                    // cost = purchase_rate × consumed_qty
                    $totalLineCogs += ($batch->rate * $consume);

                    // record first batch ID for linking
                    if (!$firstInventoryId) {
                        $firstInventoryId = $batch->id;
                    }

                    // reduce stock
                    $batch->qty -= $consume;
                    $batch->save();

                    $remaining -= $consume;
                }

                // ----------------------------------------------------
                // 2️⃣ SAVING (MRP - Selling Price)
                // ----------------------------------------------------
                $mrp = $product->mrp ?? $product->selling_price;
                $lineSaving = ($mrp - $product->selling_price) * $line['qty'];
                $totalSaved += $lineSaving;

                // ----------------------------------------------------
                // 3️⃣ GST CALCULATION
                // ----------------------------------------------------
                $gstRate = $product->gstRate ? $product->gstRate->rate : 0;
                $half = $gstRate / 2;

                $storeState  = $user->store->state;
                $branchState = $user->branches->first()->state;

                $taxable = $product->selling_price * $line['qty'];

                if ($gstRate == 0) {
                    $cgst = $sgst = $igst = 0;
                } else {
                    if ($storeState == $branchState) {
                        // INTRA
                        $cgst = ($taxable * $half) / 100;
                        $sgst = ($taxable * $half) / 100;
                        $igst = 0;
                    } else {
                        // INTER
                        $cgst = 0;
                        $sgst = 0;
                        $igst = ($taxable * $gstRate) / 100;
                    }
                }

                $totalLineGst = $cgst + $sgst + $igst;
                $amount = $taxable;

                // ----------------------------------------------------
                // 4️⃣ CREATE SALES BILL LINE
                // ----------------------------------------------------
                $salesLine = SalesBillLine::create([
                    'sales_bill_id' => $bill->id,
                    'product_id'    => $product->id,
                    'branch_id'     => $branchId,
                    'inventory_id'  => $firstInventoryId,
                    'qty'           => $line['qty'],
                    'rate'          => $product->selling_price,
                    'amount'        => $amount,
                    'cgst'          => $cgst,
                    'sgst'          => $sgst,
                    'igst'          => $igst,
                    'total_gst'     => $totalLineGst,
                    'cogs'          => $totalLineCogs,                     // Added for profit
                    'profit'        => $amount - $totalLineCogs,          // Profit calculation
                ]);

                // ----------------------------------------------------
                // 5️⃣ OUTPUT GST LEDGER (sales)
                // ----------------------------------------------------
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

                $subtotal += $amount;
                $totalGst += $totalLineGst;
                $totalCogs += $totalLineCogs;
                $totalProfit += ($taxable - $totalLineCogs);
            }

            // ----------------------------------------------------
            // 6️⃣ UPDATE SALES BILL TOTALS
            // ----------------------------------------------------
            $bill->update([
                'subtotal'     => round($subtotal, 2),
                'total_gst'    => round($totalGst, 2),
                'total_amount' => round($subtotal + $totalGst, 2),
                'total_saved'  => round($totalSaved, 2),
                'total_cogs'   => round($totalCogs, 2),
                'total_profit' => round($totalProfit, 2),
            ]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Sales bill created successfully',
                'data'    => $bill->load('lines')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'An error occurred while creating the sales bill.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function getPrintData($id)
    {
        $bill = SalesBill::with([
            'store',
            'branch',
            'user',
            'lines.product',
            'lines.gstRate'
        ])->findOrFail($id);

        $items = $bill->lines->map(function ($line) {
            return [
                'name'      => $line->product->name,
                'qty'       => $line->qty,
                'mrp'       => $line->product->mrp,
                'selling'   => $line->product->selling_price,
                'amount'    => $line->amount,
                'saved'     => ($line->product->mrp - $line->product->selling_price) * $line->qty,
                'cgst'      => $line->cgst,
                'sgst'      => $line->sgst,
                'igst'      => $line->igst,
                'gst_total' => $line->total_gst,
            ];
        });

        try {
            $pngBase64 = DNS1D::getBarcodePNG($bill->bill_no, 'C128', 3, 90);

            if (!$pngBase64) {
                return response()->json([
                    'status'  => false,
                    'message' => "Failed to generate barcode for bill_no: {$bill->bill_no}",
                ], 500);
            }

            $barcodeDataUri = "data:image/png;base64," . $pngBase64;
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Barcode generation error.',
                'error'   => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status'   => true,

            'store'    => [
                'name'    => $bill->store->name,
                'state'   => $bill->store->state,
                'phone'   => $bill->store->phone,
            ],

            'branch'   => [
                'name'    => $bill->branch->name,
                'address' => $bill->branch->address,
            ],

            'bill'     => [
                'number'       => $bill->bill_no,
                'date'         => $bill->created_at->format('d-m-Y H:i'),
                'cashier'      => $bill->user->name,
                'subtotal'     => $bill->subtotal,
                'total_gst'    => $bill->total_gst,
                'total_amount' => $bill->total_amount,
                'total_saved'  => $bill->total_saved,
                'cgst_total'   => $items->sum('cgst'),
                'sgst_total'   => $items->sum('sgst'),
            ],

            'items' => $items,

            'barcode' => $barcodeDataUri,

            'footer' => "Thank You! Visit Again"
        ]);
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
