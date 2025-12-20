<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function profitLoss(Request $request)
    {
        $user = Auth::user();

        // Date range (default: today)
        $from = $request->filled('from_date')
            ? Carbon::parse($request->from_date)->startOfDay()
            : Carbon::today()->startOfDay();

        $to = $request->filled('to_date')
            ? Carbon::parse($request->to_date)->endOfDay()
            : Carbon::today()->endOfDay();

        // Base query
        $query = DB::table('sales_bill_lines')
            ->whereBetween('created_at', [$from, $to]);

        // Role-based branch filtering
        if ($user->role === 'manager') {

            $managerBranchIds = $user->managerBranches()
                ->pluck('branch_id')
                ->toArray();

            // Safety check
            if (!empty($managerBranchIds)) {
                $query->whereIn('branch_id', $managerBranchIds);
            } else {
                // Manager with no branches → no data
                $query->whereRaw('1 = 0');
            }
        } elseif ($user->role === 'admin') {

            // Admin selected a specific branch
            if ($request->filled('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }
            // else: admin sees ALL branches (no filter)
        }

        // Aggregation
        $data = $query->selectRaw('
            COALESCE(SUM(amount), 0) AS total_sales,
            COALESCE(SUM(cogs), 0) AS total_cogs,
            COALESCE(SUM(profit), 0) AS total_profit
        ')
            ->first();

        return response()->json([
            'from_date' => $from->toDateString(),
            'to_date'   => $to->toDateString(),
            'branch_id' => $request->branch_id ?? 'ALL',
            'sales'     => round($data->total_sales, 2),
            'cogs'      => round($data->total_cogs, 2),
            'profit'    => round($data->total_profit, 2),
            'status'    => $data->total_profit >= 0 ? 'profit' : 'loss',
        ]);
    }

    public function topSellingProducts(Request $request)
    {
        $user = Auth::user();

        $query = DB::table('sales_bill_lines')
            ->select(
                'product_id',
                DB::raw('SUM(qty) as total_qty'),
                DB::raw('SUM(amount) as total_sales'),
                DB::raw('SUM(profit) as total_profit')
            );

        // Manager: only their branch
        if ($user->role === 'manager') {
            $query->where('branch_id', $user->branch_id);
        }

        // Admin: optional branch filter
        if ($user->role === 'admin' && $request->branch_id) {
            $query->where('branch_id', $request->branch_id);
        }

        return $query
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get();
    }
}
