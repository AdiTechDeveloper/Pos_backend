<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SalesReportService
{
    public function resolveFilters(array $input): array
    {
        $range = $input['date_range'] ?? 'this_month';
        $billStatus = $input['bill_status'] ?? 'all';
        $storeId = $input['store_id'] ?? null;
        $branchId = $input['branch_id'] ?? null;

        [$from, $to] = match ($range) {
            'today' => [
                Carbon::today()->startOfDay(),
                Carbon::today()->endOfDay(),
            ],

            'yesterday' => [
                Carbon::yesterday()->startOfDay(),
                Carbon::yesterday()->endOfDay(),
            ],

            'last_7_days' => [
                Carbon::now()->subDays(6)->startOfDay(),
                Carbon::now()->endOfDay(),
            ],

            'custom' => [
                Carbon::parse($input['date_from'])->startOfDay(),
                Carbon::parse($input['date_to'])->endOfDay(),
            ],

            default => [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfDay(),
            ],
        };

        return compact('from', 'to', 'range', 'billStatus', 'storeId', 'branchId');
    }

    private function applyBillFilters(\Illuminate\Database\Query\Builder $query, array $f): void
    {
        $query->whereBetween('sb.created_at', [$f['from'], $f['to']]);

        if ($f['billStatus'] !== 'all') {
            $query->where('sb.bill_status', $f['billStatus']);
        }
        if ($f['storeId']) {
            $query->where('sb.store_id', $f['storeId']);
        }
        if ($f['branchId']) {
            $query->where('sb.branch_id', $f['branchId']);
        }
    }

    public function getKpis(array $f): array
    {
        // Core bill-level aggregates
        $bill = DB::table('sales_bills as sb')
            ->when(true, fn ($q) => $this->applyBillFilters($q, $f))
            ->selectRaw('
                COUNT(sb.id)                        AS total_bills,
                COALESCE(SUM(sb.total_amount), 0)   AS gross_sales,
                COALESCE(SUM(sb.total_cogs), 0)     AS total_cogs,
                COALESCE(SUM(sb.total_profit), 0)   AS total_profit,
                COALESCE(SUM(sb.paid_amount), 0)    AS total_collected,
                COALESCE(SUM(sb.due_amount), 0)     AS total_due,
                COALESCE(SUM(sb.total_gst), 0)      AS total_gst_bills
            ')
            ->first();

        // Tax breakdown from line items (single JOIN avoids N+1)
        $tax = DB::table('sales_bills as sb')
            ->join('sales_bill_lines as sbl', 'sbl.sales_bill_id', '=', 'sb.id')
            ->when(true, fn ($q) => $this->applyBillFilters($q, $f))
            ->selectRaw('
                COALESCE(SUM(sbl.cgst), 0)      AS total_cgst,
                COALESCE(SUM(sbl.sgst), 0)      AS total_sgst,
                COALESCE(SUM(sbl.igst), 0)      AS total_igst
            ')
            ->first();

        $grossSales = (float) $bill->gross_sales;
        $totalProfit = (float) $bill->total_profit;

        return [
            'total_bills' => (int) $bill->total_bills,
            'gross_sales' => $grossSales,
            'total_cogs' => (float) $bill->total_cogs,
            'total_profit' => $totalProfit,
            'profit_margin_pct' => $grossSales > 0
                                    ? round(($totalProfit / $grossSales) * 100, 2)
                                    : 0,
            'total_collected' => (float) $bill->total_collected,
            'total_due' => (float) $bill->total_due,
            'total_gst' => (float) $bill->total_gst_bills,
            'tax_breakdown' => [
                'cgst' => (float) $tax->total_cgst,
                'sgst' => (float) $tax->total_sgst,
                'igst' => (float) $tax->total_igst,
            ],
        ];
    }

    public function getInvoiceTable(array $f): array
    {
        $rows = DB::table('sales_bills as sb')
            ->leftJoin('sales_bill_payments as sbp', 'sb.id', '=', 'sbp.sales_bill_id')
            ->when(true, fn ($q) => $this->applyBillFilters($q, $f))
            ->select([
                'sb.id',
                'sb.bill_no',
                'sb.created_at',
                'sb.subtotal',
                'sb.total_gst',
                'sb.total_amount',
                'sb.paid_amount',
                'sb.due_amount',
                'sb.total_profit',
                'sb.payment_status',
                'sb.bill_status',
                DB::raw('GROUP_CONCAT(DISTINCT sbp.method) as payment_methods'),
                DB::raw('SUM(sbp.amount) as payment_total'),
            ])
            ->groupBy(
                'sb.id',
                'sb.bill_no',
                'sb.created_at',
                'sb.subtotal',
                'sb.total_gst',
                'sb.total_amount',
                'sb.paid_amount',
                'sb.due_amount',
                'sb.total_profit',
                'sb.payment_status',
                'sb.bill_status'
            )
            ->orderByDesc('sb.created_at')
            ->get();

        // Footer totals
        $totals = [
            'subtotal' => $rows->sum('subtotal'),
            'total_gst' => $rows->sum('total_gst'),
            'total_amount' => $rows->sum('total_amount'),
            'paid_amount' => $rows->sum('paid_amount'),
            'due_amount' => $rows->sum('due_amount'),
            'total_profit' => $rows->sum('total_profit'),
        ];

        return ['rows' => $rows, 'totals' => $totals];
    }

    public function getProductPerformance(array $f): array
    {
        $rows = DB::table('sales_bills as sb')
            ->join('sales_bill_lines as sbl', 'sbl.sales_bill_id', '=', 'sb.id')
            ->join('products as p', 'p.id', '=', 'sbl.product_id')
            ->when(true, fn ($q) => $this->applyBillFilters($q, $f))
            ->groupBy('p.id', 'p.name', 'p.sku', 'p.hsn_code')
            ->selectRaw('
                p.id AS product_id,
                p.name AS product_name,
                p.sku,
                p.hsn_code,
                SUM(sbl.qty) AS qty_sold,
                COALESCE(SUM(sbl.amount), 0) AS net_revenue,
                COALESCE(SUM(sbl.profit), 0) AS total_profit,
                COALESCE(SUM(sbl.cogs), 0) AS total_cogs
            ')
            ->orderByRaw('SUM(sbl.amount) DESC')
            ->get();

        $totals = [
            'qty_sold' => $rows->sum('qty_sold'),
            'net_revenue' => $rows->sum('net_revenue'),
            'total_profit' => $rows->sum('total_profit'),
            'total_cogs' => $rows->sum('total_cogs'),
        ];

        return ['rows' => $rows, 'totals' => $totals];
    }

    public function getPaymentMethods(array $f): array
    {
        $rows = DB::table('sales_bills as sb')
            ->join('sales_bill_payments as sbp', 'sbp.sales_bill_id', '=', 'sb.id')
            ->when(true, fn ($q) => $this->applyBillFilters($q, $f))
            ->where('sbp.status', 'success')        // Only successful payments
            ->groupBy('sbp.method')
            ->selectRaw('
                sbp.method,
                COUNT(DISTINCT sb.id) AS bill_count,
                COALESCE(SUM(sbp.amount), 0) AS total_collected
            ')
            ->orderByRaw('SUM(sbp.amount) DESC')
            ->get();

        $grandTotal = $rows->sum('total_collected');

        // Attach percentage share per method
        $rows = $rows->map(function ($row) use ($grandTotal) {
            $row->share_pct = $grandTotal > 0
                ? round(($row->total_collected / $grandTotal) * 100, 1)
                : 0;

            return $row;
        });

        // Add pending/due amounts if any exist
        $dueAmount = DB::table('sales_bills as sb')
            ->when(true, fn ($q) => $this->applyBillFilters($q, $f))
            ->where('sb.due_amount', '>', 0)
            ->selectRaw('COALESCE(SUM(sb.due_amount), 0) AS total_due')
            ->first();

        return [
            'rows' => $rows,
            'grand_total' => $grandTotal,
            'total_due' => $dueAmount?->total_due ?? 0,
        ];
    }

    public function getPaymentSummary(array $f): array
    {
        $rows = DB::table('sales_bills as sb')
            ->leftJoin('sales_bill_payments as sbp', 'sb.id', '=', 'sbp.sales_bill_id')
            ->when(true, fn ($q) => $this->applyBillFilters($q, $f))
            ->select(
                'sb.id',
                'sb.bill_no',
                DB::raw('GROUP_CONCAT(DISTINCT sbp.method) as methods'),
                DB::raw('SUM(sbp.amount) as total_amount')
            )
            ->groupBy('sb.id', 'sb.bill_no')
            ->get();

        $result = [
            'cash' => ['count' => 0, 'amount' => 0],
            'online' => ['count' => 0, 'amount' => 0],
            'wallet' => ['count' => 0, 'amount' => 0],
            'split' => ['count' => 0, 'amount' => 0, 'bills' => []],
        ];

        foreach ($rows as $row) {
            $methods = explode(',', $row->methods);

            if (count($methods) > 1) {
                // Split
                $result['split']['count']++;
                $result['split']['amount'] += $row->total_amount;
                $result['split']['bills'][] = $row->bill_no;

            } else {
                $method = strtolower($methods[0]);

                if ($method === 'cash') {
                    $result['cash']['count']++;
                    $result['cash']['amount'] += $row->total_amount;
                }

                if ($method === 'online') {
                    $result['online']['count']++;
                    $result['online']['amount'] += $row->total_amount;
                }

                if ($method === 'wallet') {
                    $result['wallet']['count']++;
                    $result['wallet']['amount'] += $row->total_amount;
                }
            }
        }

        return $result;
    }

    public function getPriceOverrides(array $f): array
    {
        $rows = DB::table('sales_bills as sb')
            ->join('sales_bill_lines as sbl', 'sbl.sales_bill_id', '=', 'sb.id')
            ->join('products as p', 'p.id', '=', 'sbl.product_id')
            ->when(true, fn ($q) => $this->applyBillFilters($q, $f))
            ->where('sbl.is_price_overridden', 1)
            ->selectRaw('
                sb.bill_no,
                sb.created_at AS bill_date,
                p.name AS product_name,
                p.sku,
                sbl.qty,
                sbl.original_price,
                sbl.override_price,
                sbl.selling_price,
                ROUND(
                    (sbl.original_price - sbl.override_price) * sbl.qty, 2
                )                                   AS value_leakage
            ')
            ->orderByRaw('value_leakage DESC')
            ->get();

        $totalLeakage = $rows->sum('value_leakage');

        return ['rows' => $rows, 'total_leakage' => $totalLeakage];
    }

    public function getSalesExtremes(array $f): array
    {
        $rows = DB::table('sales_bills as sb')
            ->when(true, fn ($q) => $this->applyBillFilters($q, $f))
            ->selectRaw('
            DATE(sb.created_at) as sale_date,
            SUM(sb.total_amount) as total_sales
        ')
            ->groupBy(DB::raw('DATE(sb.created_at)'))
            ->orderBy('sale_date')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'highest_day' => null,
                'lowest_day' => null,
            ];
        }

        $highest = $rows->sortByDesc('total_sales')->first();
        $lowest = $rows->sortBy('total_sales')->first();

        return [
            'highest_day' => [
                'date' => $highest->sale_date,
                'sales' => (float) $highest->total_sales,
            ],
            'lowest_day' => [
                'date' => $lowest->sale_date,
                'sales' => (float) $lowest->total_sales,
            ],
        ];
    }
}
