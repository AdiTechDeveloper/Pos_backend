<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialReportService
{
    public function getFinancialReport(Request $request)
    {
        // 1. Initialize Filters safely
        $from = $request->from_date ?? now()->startOfMonth()->toDateTimeString();
        $to = $request->to_date ?? now()->toDateTimeString();
        $branchId = $request->branch_id;

        // 2. Base queries to avoid repetition (WITH SPECIFIC TABLE PREFIXES)
        $salesBillBase = DB::table('sales_bills')
            ->whereBetween('sales_bills.created_at', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('sales_bills.branch_id', $branchId));

        $salesBillLinesBase = DB::table('sales_bill_lines')
            ->whereBetween('sales_bill_lines.created_at', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('sales_bill_lines.branch_id', $branchId));

        // 3. Gather Sales KPIs
        $sales = (clone $salesBillBase)
            ->selectRaw('
            COUNT(*) as total_bills,
            SUM(subtotal) as total_sales,
            SUM(total_gst) as total_tax,
            SUM(total_amount) as grand_total,
            SUM(paid_amount) as received_amount,
            SUM(due_amount) as pending_amount
        ')->first();

        // 4. Gather Purchase KPIs
        $purchase = DB::table('purchase_bills')
            ->whereBetween('created_at', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('
            COUNT(*) as total_purchase_bills,
            SUM(total_amount) as total_purchase
        ')->first();

        // Calculate profit metric
        $profit = ($sales->grand_total ?? 0) - ($purchase->total_purchase ?? 0);

        // 5. Gather GST Splits
        $gst = (clone $salesBillLinesBase)
            ->selectRaw('
            SUM(cgst) as total_cgst,
            SUM(sgst) as total_sgst,
            SUM(igst) as total_igst
        ')->first();

        // 6. Chart: Daily Sales Trends
        $daily = (clone $salesBillBase)
            ->selectRaw('
            DATE(sales_bills.created_at) as date,
            SUM(total_amount) as sales,
            SUM(paid_amount) as received,
            SUM(due_amount) as due
        ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // 7. Analytics: Top 5 Products Sold
        $topProducts = (clone $salesBillLinesBase)
            ->join('products', 'products.id', '=', 'sales_bill_lines.product_id')
            ->selectRaw('
            products.name,
            SUM(sales_bill_lines.qty) as total_qty,
            SUM(sales_bill_lines.amount) as total_sales
        ')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get();

        // 8. Accounts Receivable: Customer Dues
        $dues = (clone $salesBillBase)
            ->join('customers', 'customers.id', '=', 'sales_bills.customer_id')
            ->where('sales_bills.due_amount', '>', 0)
            ->selectRaw('
            customers.name,
            SUM(sales_bills.due_amount) as total_due
        ')
            ->groupBy('customers.id', 'customers.name')
            ->orderByDesc('total_due')
            ->get();

        // 9. Format Output
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
