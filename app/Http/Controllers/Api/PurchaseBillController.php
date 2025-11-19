<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GstRate;
use App\Models\Product;
use App\Models\PurchaseBill;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PurchaseBillController extends Controller
{
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'branch_id'        => 'required|integer',
                'supplier_id'      => 'nullable|integer',
                'bill_no'          => 'required|string',
                'bill_date'        => 'required|date',

                'lines'                    => 'required|array',
                'lines.*.purchase_bill_id' => 'required|integer',
                'lines.*.product_id'       => 'required|integer',
                'lines.*.qty'              => 'required|numeric',
                'lines.*.free_qty'         => 'nullable|numeric',
                'lines.*.purchase_rate'    => 'required|numeric',
                'lines.*.discount_type'    => 'nullable|string',
                'lines.*.discount'         => 'nullable|numeric',
                'lines.*.gst_rate_id'      => 'required|integer',
                'lines.*.batch_no'         => 'nullable|string',
                'lines.*.expiry_date'      => 'nullable|date',
            ]);

            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            DB::beginTransaction();

            $bill = PurchaseBill::create([
                'store_id'      => $storeId,
                'branch_id'     => $validated['branch_id'],
                'supplier_id'   => $validated['supplier_id'] ?? null,
                'bill_no'       => $validated['bill_no'],
                'bill_date'     => $validated['bill_date'],
            ]);

            $totalTaxable = 0;
            $totalCgst = 0;
            $totalSgst = 0;
            $totalIgst = 0;

            foreach ($validated['lines'] as $line) {

                $qty = $line['qty'];
                $rate = $line['purchase_rate'];
                $discount = $line['discount'] ?? 0;
                $gstRate = GstRate::find($line['gst_rate_id']);

                // Calculate taxable
                if (($line['discount_type'] ?? null) === 'percent') {
                    $discountAmount = ($rate * $qty) * ($discount / 100);
                } else {
                    $discountAmount = $discount;
                }

                $taxable = ($rate * $qty) - $discountAmount;

                // GST split
                if ($gstRate->type === 'intra') { // CGST + SGST
                    $cgst = ($taxable * ($gstRate->rate / 2)) / 100;
                    $sgst = ($taxable * ($gstRate->rate / 2)) / 100;
                    $igst = 0;
                } else { // IGST
                    $cgst = 0;
                    $sgst = 0;
                    $igst = ($taxable * $gstRate->rate) / 100;
                }

                // Insert Purchase Line
                $bill->lines()->create([
                    'purchase_bill_id' => $line['purchase_bill_id'],
                    'product_id'    => $line['product_id'],
                    'gst_rate_id'   => $line['gst_rate_id'],
                    'qty'           => $qty,
                    'free_qty'      => $line['free_qty'] ?? 0,
                    'purchase_rate' => $rate,
                    'discount_type' => $line['discount_type'] ?? null,
                    'discount'      => $discount,
                    'hsn_code'      => $line['hsn_code'] ?? null,
                    'batch_no'      => $line['batch_no'] ?? null,
                    'expiry_date'   => $line['expiry_date'] ?? null,
                    'taxable_value' => $taxable,
                    'cgst'          => $cgst,
                    'sgst'          => $sgst,
                    'igst'          => $igst,
                ]);

                // Update totals
                $totalTaxable += $taxable;
                $totalCgst += $cgst;
                $totalSgst += $sgst;
                $totalIgst += $igst;

                // Update Product Cost Price (weighted average)
                $product = Product::find($line['product_id']);

                $oldStockValue = $product->stock * $product->cost_price;
                $newStockValue = $qty * $rate;

                $newQty = $product->stock + $qty;
                if ($newQty > 0) {
                    $product->cost_price = ($oldStockValue + $newStockValue) / $newQty;
                } else {
                    $product->cost_price = $rate;
                }

                $product->stock = $newQty;
                $product->save();
            }

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
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while creating the purchase bill.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
