<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialReportService
{
    public function getFinancialReport(Request $request)
    {
        $from = $request->from_date ?? now()->startOfMonth();
        $to = $request->to_date ?? now();
        $branchId = $request->branch_id;

        $salesQuery = DB::table('sales_bills')
            ->whereBetween('created_at', [$from, $to]);

        if ($branchId) {
            $salesQuery->where('branch_id', $branchId);
        }

        $sales = $salesQuery->selectRaw('
            COUNT(*) as total_bills,
            SUM(subtotal) as total_sales,
            SUM(total_gst) as total_tax,
            SUM(total_amount) as grand_total,
            SUM(paid_amount) as received_amount,
            SUM(due_amount) as pending_amount
        ')->first();

        $purchaseQuery = DB::table('purchase_bills')
            ->whereBetween('created_at', [$from, $to]);

        if ($branchId) {
            $purchaseQuery->where('branch_id', $branchId);
        }

        $purchase = $purchaseQuery->selectRaw('
            COUNT(*) as total_purchase_bills,
            SUM(total_amount) as total_purchase
        ')->first();

        $profit = ($sales->grand_total ?? 0) - ($purchase->total_purchase ?? 0);

        $gst = DB::table('sales_bill_lines')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('
                SUM(cgst) as total_cgst,
                SUM(sgst) as total_sgst,
                SUM(igst) as total_igst
            ')
            ->first();

        $daily = DB::table('sales_bills')
            ->whereBetween('created_at', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('
                DATE(created_at) as date,
                SUM(total_amount) as sales,
                SUM(paid_amount) as received,
                SUM(due_amount) as due
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $topProducts = DB::table('sales_bill_lines')
            ->join('products', 'products.id', '=', 'sales_bill_lines.product_id')
            ->whereBetween('sales_bill_lines.created_at', [$from, $to])
            ->selectRaw('
                products.name,
                SUM(sales_bill_lines.qty) as total_qty,
                SUM(sales_bill_lines.total_price) as total_sales
            ')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get();

        $dues = DB::table('sales_bills')
            ->join('customers', 'customers.id', '=', 'sales_bills.customer_id')
            ->where('due_amount', '>', 0)
            ->whereBetween('sales_bills.created_at', [$from, $to])
            ->selectRaw('
                customers.name,
                SUM(sales_bills.due_amount) as total_due
            ')
            ->groupBy('customers.id', 'customers.name')
            ->orderByDesc('total_due')
            ->get();

        return [
            'filters' => [
                'from' => $from,
                'to' => $to,
                'branch_id' => $branchId,
            ],

            'kpis' => [
                'total_sales' => (float) ($sales->grand_total ?? 0),
                'total_purchase' => (float) ($purchase->total_purchase ?? 0),
                'profit' => (float) $profit,
                'received_amount' => (float) ($sales->received_amount ?? 0),
                'pending_amount' => (float) ($sales->pending_amount ?? 0),
            ],

            'gst' => [
                'cgst' => (float) ($gst->total_cgst ?? 0),
                'sgst' => (float) ($gst->total_sgst ?? 0),
                'igst' => (float) ($gst->total_igst ?? 0),
            ],

            'charts' => [
                'daily_sales' => $daily,
            ],

            'top_products' => $topProducts,
            'customer_dues' => $dues,
        ];
    }
}
