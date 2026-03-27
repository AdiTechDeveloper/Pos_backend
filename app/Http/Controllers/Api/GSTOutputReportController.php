<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GSTOutputReportController extends Controller
{
    // public function gstOutputReport(Request $request)
    // {
    //     // Validate filters
    //     $validated = $request->validate([
    //         'branch_id' => 'nullable|integer',
    //         'start_date' => 'required|date',
    //         'end_date' => 'required|date',
    //     ]);

    //     $user = Auth::user();
    //     $storeId = $user->store_id;
    //     $branchId = $validated['branch_id'];
    //     $startDate = $validated['start_date'];
    //     $endDate = $validated['end_date'];

    //     // Query GST Output (Sales GST)
    //     $records = DB::table('gst_output_ledgers as gol')
    //         ->join('sales_bills as sb', 'sb.id', '=', 'gol.sales_bill_id')
    //         ->select(
    //             'sb.id as sales_bill_id',
    //             'sb.bill_no',
    //             'sb.created_at as invoice_date',
    //             'sb.subtotal as taxable_value',
    //             DB::raw('SUM(gol.cgst) as total_cgst'),
    //             DB::raw('SUM(gol.sgst) as total_sgst'),
    //             DB::raw('SUM(gol.igst) as total_igst'),
    //             DB::raw('SUM(gol.total_gst) as total_tax'),
    //             'sb.total_amount'
    //         )
    //         ->where('sb.bill_status', 'completed')
    //         ->where('sb.store_id', $storeId)
    //         ->where('sb.branch_id', $branchId)
    //         ->whereBetween(DB::raw('DATE(sb.created_at)'), [$startDate, $endDate])
    //         ->groupBy(
    //             'sb.id',
    //             'sb.bill_no',
    //             'sb.created_at',
    //             'sb.subtotal',
    //             'sb.total_amount'
    //         )
    //         ->orderBy('sb.created_at', 'asc')
    //         ->get();

    //     // Return JSON for React
    //     return response()->json([
    //         'success' => true,
    //         'message' => 'GST Output Report fetched successfully',
    //         'data' => $records,
    //     ]);
    // }

    public function gstOutputReport(Request $request)
    {
        // Validate filters
        $validated = $request->validate([
            'branch_id' => 'nullable|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
        ]);

        $user = Auth::user();

        // Always store_id from logged-in user
        $storeId = $user->store_id;

        // Handle role conditions
        if ($user->role === 'admin') {

            // Admin can select any branch under their store
            $branchId = $validated['branch_id'];   // can be null (all branches)

        } elseif ($user->role === 'manager') {

            // Manager can see ONLY their assigned branch
            $branchId = $user->branch_id;

        } else {

            // Other roles fallback (optional)
            $branchId = $validated['branch_id'];
        }

        $startDate = $validated['start_date'];
        $endDate = $validated['end_date'];

        // Query GST Output (Sales GST)
        $records = DB::table('gst_output_ledgers as gol')
            ->join('sales_bills as sb', 'sb.id', '=', 'gol.sales_bill_id')
            ->select(
                'sb.id as sales_bill_id',
                'sb.bill_no',
                'sb.created_at as invoice_date',
                'sb.subtotal as taxable_value',
                DB::raw('SUM(gol.cgst) as total_cgst'),
                DB::raw('SUM(gol.sgst) as total_sgst'),
                DB::raw('SUM(gol.igst) as total_igst'),
                DB::raw('SUM(gol.total_gst) as total_tax'),
                'sb.total_amount'
            )
            ->where('sb.bill_status', 'completed')
            ->where('sb.store_id', $storeId)
            ->when($branchId, function ($q) use ($branchId) {
                return $q->where('sb.branch_id', $branchId);
            })
            ->whereBetween(DB::raw('DATE(sb.created_at)'), [$startDate, $endDate])
            ->groupBy(
                'sb.id',
                'sb.bill_no',
                'sb.created_at',
                'sb.subtotal',
                'sb.total_amount'
            )
            ->orderBy('sb.created_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'GST Output Report fetched successfully',
            'data' => $records,
        ]);
    }

    // public function gstr3bSummary(Request $request)
    // {
    //     $validated = $request->validate([
    //         'store_id' => 'required|integer',
    //         'branch_id' => 'required|integer',
    //         'start_date' => 'required|date',
    //         'end_date' => 'required|date',
    //     ]);

    //     $storeId = $validated['store_id'];
    //     $branchId = $validated['branch_id'];

    //     // OUTPUT GST (Sales)
    //     $sales = DB::table('gst_output_ledgers as gol')
    //         ->join('sales_bills as sb', 'sb.id', '=', 'gol.sales_bill_id')
    //         ->select(
    //             DB::raw('SUM(sb.subtotal) as taxable_value'),
    //             DB::raw('SUM(gol.cgst) as cgst'),
    //             DB::raw('SUM(gol.sgst) as sgst'),
    //             DB::raw('SUM(gol.igst) as igst'),
    //             DB::raw('SUM(gol.total_gst) as total_gst')
    //         )
    //         ->where('sb.bill_status', 'completed')
    //         ->where('sb.store_id', $storeId)
    //         ->where('sb.branch_id', $branchId)
    //         ->whereBetween(DB::raw('DATE(sb.created_at)'), [$validated['start_date'], $validated['end_date']])
    //         ->first();

    //     // INPUT GST (Purchases)
    //     $purchases = DB::table('itc_entries as ie')
    //         ->join('purchase_bills as pb', 'pb.id', '=', 'ie.purchase_bill_id')
    //         ->select(
    //             DB::raw('SUM(pb.taxable_value) as taxable_value'),
    //             DB::raw('SUM(ie.cgst) as cgst'),
    //             DB::raw('SUM(ie.sgst) as sgst'),
    //             DB::raw('SUM(ie.igst) as igst'),
    //             DB::raw('SUM(ie.total_itc) as total_gst')
    //         )
    //         ->where('pb.store_id', $storeId)
    //         ->where('pb.branch_id', $branchId)
    //         ->whereBetween(DB::raw('DATE(pb.bill_date)'), [$validated['start_date'], $validated['end_date']])
    //         ->first();

    //     return response()->json([
    //         'success' => true,
    //         'gstr3b' => [
    //             'outward_supplies' => $sales,
    //             'inward_supplies' => $purchases,
    //             'net_tax_payable' => [
    //                 'cgst' => ($sales->cgst ?? 0) - ($purchases->cgst ?? 0),
    //                 'sgst' => ($sales->sgst ?? 0) - ($purchases->sgst ?? 0),
    //                 'igst' => ($sales->igst ?? 0) - ($purchases->igst ?? 0),
    //             ],
    //         ],
    //     ]);
    // }

    public function gstr3bSummary(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'nullable|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
        ]);

        $user = Auth::user();

        $storeId = $user->store_id;

        if ($user->role === 'admin') {
            $branchId = $validated['branch_id'];
        } elseif ($user->role === 'manager') {
            $branchId = $user->branch_id;
        } else {
            $branchId = $validated['branch_id'];
        }

        $startDate = $validated['start_date'];
        $endDate = $validated['end_date'];

        // OUTWARD TAX (Sales GST)
        $sales = DB::table('gst_output_ledgers as gol')
            ->join('sales_bills as sb', 'sb.id', '=', 'gol.sales_bill_id')
            ->select(
                DB::raw('COALESCE(SUM(sb.subtotal), 0) as taxable_value'),
                DB::raw('COALESCE(SUM(gol.cgst), 0) as cgst'),
                DB::raw('COALESCE(SUM(gol.sgst), 0) as sgst'),
                DB::raw('COALESCE(SUM(gol.igst), 0) as igst'),
                DB::raw('COALESCE(SUM(gol.total_gst), 0) as total_gst')
            )
            ->where('sb.bill_status', 'completed')
            ->where('sb.store_id', $storeId)
            ->when($branchId, fn ($q) => $q->where('sb.branch_id', $branchId))
            ->whereBetween(DB::raw('DATE(sb.created_at)'), [$startDate, $endDate])
            ->first();

        // INWARD TAX (Purchase ITC)
        $purchases = DB::table('itc_entries as ie')
            ->join('purchase_bills as pb', 'pb.id', '=', 'ie.purchase_bill_id')
            ->select(
                DB::raw('COALESCE(SUM(pb.taxable_value), 0) as taxable_value'),
                DB::raw('COALESCE(SUM(ie.cgst), 0) as cgst'),
                DB::raw('COALESCE(SUM(ie.sgst), 0) as sgst'),
                DB::raw('COALESCE(SUM(ie.igst), 0) as igst'),
                DB::raw('COALESCE(SUM(ie.total_itc), 0) as total_gst')
            )
            ->where('pb.store_id', $storeId)
            ->when($branchId, fn ($q) => $q->where('pb.branch_id', $branchId))
            ->whereBetween(DB::raw('DATE(pb.bill_date)'), [$startDate, $endDate])
            ->first();

        // NET TAX PAYABLE (OUTWARD - INWARD)
        $net = [
            'cgst' => round(($sales->cgst ?? 0) - ($purchases->cgst ?? 0), 2),
            'sgst' => round(($sales->sgst ?? 0) - ($purchases->sgst ?? 0), 2),
            'igst' => round(($sales->igst ?? 0) - ($purchases->igst ?? 0), 2),
        ];

        // ITC Summary (Recommended by GSTR-3B)
        $itcSummary = [
            'itc_available' => round($purchases->total_gst ?? 0, 2),
            'itc_utilized' => round($sales->total_gst ?? 0, 2),
            'itc_balance' => round(($purchases->total_gst ?? 0) - ($sales->total_gst ?? 0), 2),
        ];

        // RETURN RESPONSE
        return response()->json([
            'success' => true,
            'gstr3b' => [
                'outward_supplies' => [
                    'taxable_value' => round($sales->taxable_value ?? 0, 2),
                    'cgst' => round($sales->cgst ?? 0, 2),
                    'sgst' => round($sales->sgst ?? 0, 2),
                    'igst' => round($sales->igst ?? 0, 2),
                    'total_gst' => round($sales->total_gst ?? 0, 2),
                ],
                'inward_supplies' => [
                    'taxable_value' => round($purchases->taxable_value ?? 0, 2),
                    'cgst' => round($purchases->cgst ?? 0, 2),
                    'sgst' => round($purchases->sgst ?? 0, 2),
                    'igst' => round($purchases->igst ?? 0, 2),
                    'total_gst' => round($purchases->total_gst ?? 0, 2),
                ],
                'net_tax_payable' => $net,
                'itc_summary' => $itcSummary,
            ],
        ]);
    }

    // public function gstr1Summary(Request $request)
    // {
    //     $validated = $request->validate([
    //         'store_id' => 'required|integer',
    //         'branch_id' => 'required|integer',
    //         'start_date' => 'required|date',
    //         'end_date' => 'required|date',
    //     ]);

    //     $storeId = $validated['store_id'];
    //     $branchId = $validated['branch_id'];

    //     $invoices = DB::table('gst_output_ledgers as gol')
    //         ->join('sales_bills as sb', 'sb.id', '=', 'gol.sales_bill_id')
    //         ->select(
    //             'sb.id',
    //             'sb.bill_no',
    //             'sb.total_amount',
    //             'sb.subtotal as taxable_value',
    //             DB::raw('SUM(gol.cgst) as cgst'),
    //             DB::raw('SUM(gol.sgst) as sgst'),
    //             DB::raw('SUM(gol.igst) as igst'),
    //             DB::raw('SUM(gol.total_gst) as total_gst')
    //         )
    //         ->where('sb.bill_status', 'completed')
    //         ->where('sb.store_id', $storeId)
    //         ->where('sb.branch_id', $branchId)
    //         ->whereBetween(DB::raw('DATE(sb.created_at)'), [$validated['start_date'], $validated['end_date']])
    //         ->groupBy('sb.id', 'sb.bill_no', 'sb.total_amount', 'sb.subtotal')
    //         ->get();

    //     // Classification starts here
    //     $b2b = collect();
    //     $b2c_large = collect();
    //     $b2c_small = collect();

    //     foreach ($invoices as $inv) {

    //         $is_interstate = $inv->igst > 0;
    //         $is_large = $inv->total_amount > 250000;

    //         if ($is_interstate && $is_large) {
    //             $b2c_large->push($inv);
    //         } else {
    //             $b2c_small->push($inv);
    //         }
    //     }

    //     return response()->json([
    //         'success' => true,
    //         'gstr1' => [
    //             'b2b' => $b2b,
    //             'b2c_large' => $b2c_large,
    //             'b2c_small' => $b2c_small,
    //         ],
    //     ]);
    // }

    public function gstr1Summary(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'nullable|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
        ]);

        $user = Auth::user();

        $storeId = $user->store_id;

        if ($user->role === 'admin') {
            $branchId = $validated['branch_id'];
        } elseif ($user->role === 'manager') {
            $branchId = $user->branch_id;
        } else {
            $branchId = $validated['branch_id'];
        }

        $invoices = DB::table('gst_output_ledgers as gol')
            ->join('sales_bills as sb', 'sb.id', '=', 'gol.sales_bill_id')
            ->select(
                'sb.id',
                'sb.bill_no',
                'sb.total_amount',
                'sb.subtotal as taxable_value',
                DB::raw('SUM(gol.cgst) as cgst'),
                DB::raw('SUM(gol.sgst) as sgst'),
                DB::raw('SUM(gol.igst) as igst'),
                DB::raw('SUM(gol.total_gst) as total_gst')
            )
            ->where('sb.bill_status', 'completed')
            ->where('sb.store_id', $storeId)
            ->when($branchId, fn ($q) => $q->where('sb.branch_id', $branchId))
            ->whereBetween(DB::raw('DATE(sb.created_at)'), [$validated['start_date'], $validated['end_date']])
            ->groupBy('sb.id', 'sb.bill_no', 'sb.total_amount', 'sb.subtotal')
            ->get();

        $b2b = collect();
        $b2c_large = collect();
        $b2c_small = $invoices;

        return response()->json([
            'success' => true,
            'gstr1' => [
                'b2b' => $b2b,
                'b2c_large' => $b2c_large,
                'b2c_small' => $b2c_small,
            ],
        ]);
    }
}
