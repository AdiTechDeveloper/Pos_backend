<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesBill;
use App\Models\SalesBillLine;
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

            $managerBranchIds = $user->branches()
                ->pluck('branch_id')
                ->toArray();

            // Safety check
            if (! empty($managerBranchIds)) {
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
            'to_date' => $to->toDateString(),
            'branch_id' => $request->branch_id ?? 'ALL',
            'sales' => round($data->total_sales, 2),
            'cogs' => round($data->total_cogs, 2),
            'profit' => round($data->total_profit, 2),
            'status' => $data->total_profit >= 0 ? 'profit' : 'loss',
        ]);
    }

    public function topSellingProducts(Request $request)
    {
        $user = Auth::user();

        if (! in_array($user->role, ['admin', 'manager'])) {
            abort(403, 'Unauthorized');
        }

        $query = SalesBillLine::query()
            ->select([
                'product_id',
                DB::raw('SUM(qty) as total_qty'),
                DB::raw('SUM(amount) as total_sales'),
                DB::raw('SUM(profit) as total_profit')
           ] )
            ->with('product:id,name,sku');

        // ADMIN → only own store
        if ($user->role === 'admin') {
            $query->whereHas('branch', function ($q) use ($user) {
                $q->where('store_id', $user->store_id);
            });

            if ($request->filled('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }
        }

        // MANAGER → only assigned branches
        if ($user->role === 'manager') {
            $branchIds = $user->branches()->pluck('branches.id');
            $query->whereIn('branch_id', $branchIds);
        }

        return $query
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get();
    }

    public function stockSummary(Request $request)
    {
        $user = Auth::user();
        $storeId = $user->store_id;

        // Determine Branch ID based on Role
        $branchId = null;
        if ($user->role === 'admin') {
            $branchId = $request->branch_id;
        } elseif ($user->role === 'manager') {
            $branchId = DB::table('branch_staff')
                ->where('user_id', $user->id)
                ->value('branch_id');

            if (! $branchId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No branch assigned to this manager',
                    'data' => [],
                ], 403);
            }
        } else {
            $branchId = $request->branch_id;
        }

        // Date handling
        $startDate = $request->start_date ?? now()->startOfMonth()->toDateString();
        $endDate = $request->end_date ?? now()->endOfMonth()->toDateString();

        $lastStart = \Carbon\Carbon::parse($startDate)->subMonth()->startOfMonth()->toDateString();
        $lastEnd = \Carbon\Carbon::parse($startDate)->subMonth()->endOfMonth()->toDateString();

        $perPage = $request->per_page ?? 10;

        // Main Query
        $query = DB::table('products as p')
            ->selectRaw('
            p.id as product_id,
            p.name as product_name,
            p.barcode as barcode,
            COALESCE(pur_before.total_purchase, 0) - COALESCE(sal_before.total_sale, 0) as opening_stock,
            COALESCE(pur_month.total_purchase, 0) as purchased,
            COALESCE(sal_month.total_sale, 0) as sold,
            COALESCE(inv.stock, 0) as closing_stock,
            COALESCE(sal_last.total_sale, 0) as last_month_sold
        ')
            ->where('p.store_id', $storeId)
            ->when($request->search, fn ($q) => $q->where('p.name', 'like', "%{$request->search}%"))
            ->when($request->product_id, fn ($q) => $q->where('p.id', $request->product_id));

        // Only show products that have inventory in the selected/assigned branch
        if ($branchId) {
            $query->whereExists(function ($q) use ($branchId) {
                $q->select(DB::raw(1))
                    ->from('inventories as i')
                    ->whereColumn('i.product_id', 'p.id')
                    ->where('i.branch_id', $branchId);
            });
        }

        // Purchase BEFORE
        $query->leftJoin(DB::raw('(
        SELECT pl.product_id, SUM(pl.qty + pl.free_qty) AS total_purchase
        FROM purchase_lines pl
        JOIN purchase_bills pb ON pb.id = pl.purchase_bill_id
        WHERE pb.bill_date < ? '.($branchId ? 'AND pb.branch_id = ?' : '').'
        GROUP BY pl.product_id
        ) AS pur_before'), function ($join) use ($startDate, $branchId) {
            $join->on('pur_before.product_id', '=', 'p.id');
            $join->addBinding($branchId ? [$startDate, $branchId] : [$startDate], 'join');
        });

        // Sales BEFORE
        $query->leftJoin(DB::raw('(
        SELECT sl.product_id, SUM(sl.qty) AS total_sale
        FROM sales_bill_lines sl
        JOIN sales_bills sb ON sb.id = sl.sales_bill_id
        WHERE sb.created_at < ? '.($branchId ? 'AND sb.branch_id = ?' : '').'
        GROUP BY sl.product_id
        ) AS sal_before'), function ($join) use ($startDate, $branchId) {
            $join->on('sal_before.product_id', '=', 'p.id');
            $join->addBinding($branchId ? [$startDate, $branchId] : [$startDate], 'join');
        });

        // Purchase THIS MONTH
        $query->leftJoin(DB::raw('(
        SELECT pl.product_id, SUM(pl.qty + pl.free_qty) AS total_purchase
        FROM purchase_lines pl
        JOIN purchase_bills pb ON pb.id = pl.purchase_bill_id
        WHERE pb.bill_date BETWEEN ? AND ? '.($branchId ? 'AND pb.branch_id = ?' : '').'
        GROUP BY pl.product_id
        ) AS pur_month'), function ($join) use ($startDate, $endDate, $branchId) {
            $join->on('pur_month.product_id', '=', 'p.id');
            $join->addBinding($branchId ? [$startDate, $endDate, $branchId] : [$startDate, $endDate], 'join');
        });

        // Sales THIS MONTH
        $query->leftJoin(DB::raw('(
        SELECT sl.product_id, SUM(sl.qty) AS total_sale
        FROM sales_bill_lines sl
        JOIN sales_bills sb ON sb.id = sl.sales_bill_id
        WHERE sb.created_at BETWEEN ? AND ? '.($branchId ? 'AND sb.branch_id = ?' : '').'
        GROUP BY sl.product_id
        ) AS sal_month'), function ($join) use ($startDate, $endDate, $branchId) {
            $join->on('sal_month.product_id', '=', 'p.id');
            $join->addBinding($branchId ? [$startDate, $endDate, $branchId] : [$startDate, $endDate], 'join');
        });

        // Sales LAST MONTH
        $query->leftJoin(DB::raw('(
        SELECT sl.product_id, SUM(sl.qty) AS total_sale
        FROM sales_bill_lines sl
        JOIN sales_bills sb ON sb.id = sl.sales_bill_id
        WHERE sb.created_at BETWEEN ? AND ? '.($branchId ? 'AND sb.branch_id = ?' : '').'
        GROUP BY sl.product_id
        ) AS sal_last'), function ($join) use ($lastStart, $lastEnd, $branchId) {
            $join->on('sal_last.product_id', '=', 'p.id');
            $join->addBinding($branchId ? [$lastStart, $lastEnd, $branchId] : [$lastStart, $lastEnd], 'join');
        });

        // INVENTORY (Current Branch Stock)
        $query->leftJoin(DB::raw('(
        SELECT product_id, SUM(qty - sold_qty - expired_qty) AS stock
        FROM inventories
        '.($branchId ? 'WHERE branch_id = ?' : '').'
        GROUP BY product_id
        ) AS inv'), function ($join) use ($branchId) {
            $join->on('inv.product_id', '=', 'p.id');
            if ($branchId) {
                $join->addBinding([$branchId], 'join');
            }
        });

        $products = $query->paginate($perPage);

        // Data Transformation
        $data = collect($products->items())->map(function ($item) {
            $totalAvailable = $item->opening_stock + $item->purchased;
            $percentage = $totalAvailable > 0
                ? round(($item->sold / $totalAvailable) * 100, 2)
                : 0;

            $trend = match (true) {
                $item->last_month_sold == 0 && $item->sold > 0 => 'new',
                $item->sold > $item->last_month_sold => 'up',
                $item->sold < $item->last_month_sold => 'down',
                default => 'same',
            };

            $stockHealth = match (true) {
                $item->closing_stock <= 0 => 'out_of_stock',
                $percentage > 70 => 'fast_moving',
                $percentage < 10 => 'slow_moving',
                default => 'normal',
            };

            return [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'barcode' => $item->barcode,
                'opening_stock' => (float) $item->opening_stock,
                'purchased' => (float) $item->purchased,
                'sold' => (float) $item->sold,
                'closing_stock' => (float) $item->closing_stock,
                'sales_percentage' => $percentage,
                'trend' => $trend,
                'stock_health' => $stockHealth,
                'dead_stock' => ($item->sold == 0 && $item->closing_stock > 0),
            ];
        });

        return response()->json([
            'status' => true,
            'data' => $data,
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function purchaseSummary(Request $request)
    {
        $user = Auth::user();
        $storeId = $user->store_id;

        $branchId = null;
        if ($user->role === 'admin') {
            $branchId = $request->branch_id;
        } elseif ($user->role === 'manager') {
            $branchId = DB::table('branch_staff')
                ->where('user_id', $user->id)
                ->value('branch_id');

            if (! $branchId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No branch assigned to this manager',
                    'data' => [],
                ], 403);
            }
        } else {
            $branchId = $request->branch_id;
        }

        $start = $request->start_date;
        $end = $request->end_date;
        $groupBy = $request->group_by ?? 'date';
        $supplierId = $request->supplier_id;
        $brandId = $request->brand_id;
        $productId = $request->product_id;
        $includeBills = $request->include_bills;

        $query = DB::table('purchase_bills as pb')
            ->join('purchase_lines as pl', 'pl.purchase_bill_id', '=', 'pb.id')
            ->leftJoin('products as p', 'p.id', '=', 'pl.product_id')
            ->leftJoin('gst_rates as g', 'g.id', '=', 'pl.gst_rate_id')
            ->where('pb.store_id', $storeId);

        if ($start === $end) {
            $query->whereDate('pb.bill_date', $start);
        } else {
            $query->whereDate('pb.bill_date', '>=', $start)
                ->whereDate('pb.bill_date', '<=', $end);
        }

        // Branch Filter
        if (! empty($branchId)) {
            $query->where('pb.branch_id', $branchId);
        }

        // Supplier Filter
        if (! empty($supplierId)) {
            $query->where('pb.supplier_id', $supplierId);
        }

        // Brand Filter
        if (! empty($brandId)) {
            $query->where('p.brand_id', $brandId);
        }

        // Product Filter
        if (! empty($productId)) {
            $query->where('pl.product_id', $productId);
        }

        $labelIdCol = null;
        switch ($groupBy) {
            case 'brand':
                $query->leftJoin('brands as b', 'b.id', '=', 'p.brand_id');
                $labelCol = 'b.name';
                $labelIdCol = 'b.id';
                break;
            case 'product':
                $labelCol = 'p.name';
                $labelIdCol = 'p.id';
                break;
            case 'supplier':
                $query->leftJoin('suppliers as s', 's.id', '=', 'pb.supplier_id');
                $labelCol = 's.name';
                $labelIdCol = 'pb.supplier_id';
                break;
            case 'gst_rate':
                $labelCol = 'g.rate';
                $labelIdCol = 'g.id';
                break;
            default:
                $labelCol = 'pb.bill_date';
                $labelIdCol = null;
        }

        $report = $query
            ->selectRaw(($labelIdCol ? "$labelIdCol as label_id," : '')."COALESCE($labelCol, 'N/A') as label")
            ->selectRaw('SUM(pl.qty + COALESCE(pl.free_qty, 0)) as total_qty')
            ->selectRaw('ROUND(SUM(pl.taxable_value), 2) as total_taxable')
            ->selectRaw('ROUND(SUM(pl.cgst + pl.sgst + pl.igst), 2) as total_gst')
            ->selectRaw('ROUND(SUM(pl.taxable_value + pl.cgst + pl.sgst + pl.igst), 2) as total_amount')
            ->when($labelIdCol, function ($q) use ($labelIdCol, $labelCol) {
                $q->groupBy($labelIdCol, $labelCol);
            }, function ($q) use ($labelCol) {
                $q->groupBy($labelCol);
            })
            ->orderBy('label', 'asc')
            ->get();

        $taxSlabs = DB::table('purchase_lines as pl')
            ->join('purchase_bills as pb', 'pb.id', '=', 'pl.purchase_bill_id')
            ->leftJoin('gst_rates as g', 'g.id', '=', 'pl.gst_rate_id')
            ->leftJoin('products as p', 'p.id', '=', 'pl.product_id') // Added for brand filtering
            ->where('pb.store_id', $storeId)
            ->when($start === $end, fn ($q) => $q->whereDate('pb.bill_date', $start), fn ($q) => $q->whereBetween('pb.bill_date', [$start, $end]))
            ->when($branchId, fn ($q) => $q->where('pb.branch_id', $branchId))
            ->when($supplierId, fn ($q) => $q->where('pb.supplier_id', $supplierId))
            ->when($brandId, fn ($q) => $q->where('p.brand_id', $brandId))
            ->when($productId, fn ($q) => $q->where('pl.product_id', $productId))
            ->selectRaw('COALESCE(g.rate, 0) as slab_name, g.rate')
            ->selectRaw('ROUND(SUM(pl.taxable_value), 2) as taxable')
            ->selectRaw('ROUND(SUM(pl.cgst + pl.sgst + pl.igst), 2) as gst')
            ->groupBy('g.id', 'g.rate')
            ->get();

        $bills = [];
        if ($includeBills) {
            $bills = DB::table('purchase_bills as pb')
                ->leftJoin('suppliers as s', 's.id', '=', 'pb.supplier_id')
                ->where('pb.store_id', $storeId)
                ->when($start === $end, fn ($q) => $q->whereDate('pb.bill_date', $start), fn ($q) => $q->whereBetween('pb.bill_date', [$start, $end]))
                ->when($branchId, fn ($q) => $q->where('pb.branch_id', $branchId))
                ->when($supplierId, fn ($q) => $q->where('pb.supplier_id', $supplierId))
                // Only show bills containing the specific product/brand if filtered
                ->when($productId || $brandId, function ($q) use ($productId, $brandId) {
                    $q->whereExists(function ($sq) use ($productId, $brandId) {
                        $sq->select(DB::raw(1))
                            ->from('purchase_lines as pl_inner')
                            ->leftJoin('products as p_inner', 'p_inner.id', '=', 'pl_inner.product_id')
                            ->whereColumn('pl_inner.purchase_bill_id', 'pb.id')
                            ->when($productId, fn ($inner) => $inner->where('pl_inner.product_id', $productId))
                            ->when($brandId, fn ($inner) => $inner->where('p_inner.brand_id', $brandId));
                    });
                })
                ->select('pb.id', 'pb.bill_no', 'pb.bill_date', 'pb.taxable_value', 'pb.total_tax', 'pb.total_amount', 's.name as supplier_name')
                ->orderBy('pb.bill_date', 'desc')
                ->limit(500)
                ->get();
        }

        return response()->json([
            'success' => true,
            'meta' => [
                'start_date' => $start,
                'end_date' => $end,
                'group_by' => $groupBy,
                'branch_id' => $branchId,
            ],
            'report' => $report,
            'tax_slabs' => $taxSlabs,
            'bills' => $bills,
        ]);
    }

    public function salesSummary(Request $request)
    {
        $query = SalesBill::from('sales_bills as sb');

        /* DATE RANGE */
        if ($request->start_date && $request->end_date) {
            $query->whereBetween('sb.created_at', [
                $request->start_date.' 00:00:00',
                $request->end_date.' 23:59:59',
            ]);
        }

        /* BRANCH FILTER */
        if (! empty($request->branch_id)) {
            $query->where('sb.branch_id', $request->branch_id);
        }

        /* AGGREGATED DAILY SALES */
        $data = $query
            ->selectRaw('DATE(sb.created_at) as date')
            ->selectRaw('COUNT(sb.id) as bills')
            ->selectRaw('SUM(sb.subtotal) as total_taxable')
            ->selectRaw('SUM(sb.total_gst) as total_gst')
            ->selectRaw('SUM(sb.total_amount) as total_amount')
            ->selectRaw('SUM(sb.total_saved) as total_discount')
            ->selectRaw('SUM(sb.total_cogs) as total_cost')
            ->selectRaw('SUM(sb.total_profit) as total_profit')
            ->groupBy(DB::raw('DATE(sb.created_at)'))
            ->orderBy('date')
            ->get();

        return response()->json([
            'success' => true,
            'report' => $data,
        ]);
    }

    public function salesAnalytics(Request $request)
    {
        $start = $request->start_date.' 00:00:00';
        $end = $request->end_date.' 23:59:59';

        $branchId = $request->branch_id;

        // DAILY SALES SUMMARY
        $daily = DB::table('sales_bills')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('COUNT(id) as bills')
            ->selectRaw('SUM(subtotal) as total_taxable')
            ->selectRaw('SUM(total_gst) as total_gst')
            ->selectRaw('SUM(total_amount) as total_amount')
            ->selectRaw('SUM(total_saved) as total_discount')
            ->selectRaw('SUM(total_cogs) as total_cost')
            ->selectRaw('SUM(total_profit) as total_profit')
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        // HOURLY SALES HEATMAP
        $hourly = DB::table('sales_bills')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('HOUR(created_at) as hour')
            ->selectRaw('SUM(total_amount) as sales')
            ->groupBy('hour')
            ->orderBy('hour')
            ->pluck('sales', 'hour');

        // TOP 10 BEST-SELLING PRODUCTS
        $topProducts = DB::table('sales_bill_lines as l')
            ->join('sales_bills as b', 'b.id', '=', 'l.sales_bill_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->when($branchId, fn ($q) => $q->where('l.branch_id', $branchId))
            ->whereBetween('b.created_at', [$start, $end])
            ->select('p.id', 'p.name')
            ->selectRaw('SUM(l.qty) as total_qty')
            ->selectRaw('SUM(l.amount) as total_amount')
            ->groupBy('p.id', 'p.name')
            ->orderBy('total_qty', 'desc')
            ->limit(10)
            ->get();

        // SLOW MOVING PRODUCTS (bottom 10)
        $slowProducts = DB::table('sales_bill_lines as l')
            ->join('sales_bills as b', 'b.id', '=', 'l.sales_bill_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->when($branchId, fn ($q) => $q->where('l.branch_id', $branchId))
            ->whereBetween('b.created_at', [$start, $end])
            ->select('p.id', 'p.name')
            ->selectRaw('SUM(l.qty) as sold_qty')
            ->groupBy('p.id', 'p.name')
            ->orderBy('sold_qty', 'asc')
            ->limit(10)
            ->get();

        // PAYMENT METHOD BREAKDOWN
        $payments = DB::table('sales_bill_payments')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method');

        // HIGHEST SALE DAY
        $highestDay = DB::table('sales_bills')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('SUM(total_amount) as sale')
            ->groupBy('date')
            ->orderBy('sale', 'desc')
            ->first();

        // LOWEST SALE DAY
        $lowestDay = DB::table('sales_bills')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('SUM(total_amount) as sale')
            ->groupBy('date')
            ->orderBy('sale', 'asc')
            ->first();

        // BRANCHWISE PERFORMANCE
        $branchPerformance = DB::table('sales_bills as b')
            ->join('branches as br', 'br.id', '=', 'b.branch_id')
            ->whereBetween('b.created_at', [$start, $end])
            ->select('br.name as branch')
            ->selectRaw('COUNT(b.id) as bills')
            ->selectRaw('SUM(b.total_amount) as sales')
            ->selectRaw('SUM(b.total_profit) as profit')
            ->groupBy('br.id', 'br.name')
            ->orderBy('sales', 'desc')
            ->get();

        // BRAND / CATEGORY SALES
        $brandSales = DB::table('sales_bill_lines as l')
            ->join('sales_bills as b', 'b.id', '=', 'l.sales_bill_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->join('brands as br', 'br.id', '=', 'p.brand_id')
            ->whereBetween('b.created_at', [$start, $end])
            ->select('br.id', 'br.name')
            ->selectRaw('SUM(l.qty) as qty')
            ->selectRaw('SUM(l.amount) as amount')
            ->groupBy('br.id', 'br.name')
            ->orderBy('amount', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'meta' => [
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'branch_id' => $branchId,
            ],
            'summary' => $daily,
            'hourly_heatmap' => $hourly,
            'top_products' => $topProducts,
            'slow_products' => $slowProducts,
            'payment_methods' => $payments,
            'highest_sale_day' => $highestDay,
            'lowest_sale_day' => $lowestDay,
            'branch_performance' => $branchPerformance,
            'brand_sales' => $brandSales,
        ]);
    }
}
