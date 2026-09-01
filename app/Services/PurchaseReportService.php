<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PurchaseReportService
{
    public function resolveFilters(array $input): array
    {
        $range = $input['date_range'] ?? 'this_month';
        $storeId = $input['store_id'] ?? null;
        $branchId = $input['branch_id'] ?? null;
        $supplierId = $input['supplier_id'] ?? null;
        $isLost = $input['is_lost'] ?? null; 

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
                Carbon::now()->subDays(6)->startOfDay(), // Today + previous 6 days = 7 days
                Carbon::now()->endOfDay(),
            ],
            'custom' => [
                ! empty($input['date_from']) ? Carbon::parse($input['date_from'])->startOfDay() : Carbon::now()->startOfMonth(),
                ! empty($input['date_to']) ? Carbon::parse($input['date_to'])->endOfDay() : Carbon::now()->endOfDay(),
            ],
            default => [
                Carbon::now()->startOfMonth()->startOfDay(), // this_month
                Carbon::now()->endOfDay(),
            ],
        };

        return compact('from', 'to', 'range', 'storeId', 'branchId', 'supplierId', 'isLost');
    }

    private function applyBillFilters(\Illuminate\Database\Query\Builder $query, array $f): void
    {
        $query->whereBetween('pb.bill_date', [$f['from'], $f['to']]);

        if ($f['storeId']) {
            $query->where('pb.store_id', $f['storeId']);
        }
        if ($f['branchId']) {
            $query->where('pb.branch_id', $f['branchId']);
        }
        if ($f['supplierId']) {
            $query->where('pb.supplier_id', $f['supplierId']);
        }
        if (! is_null($f['isLost'])) {
            $query->where('pb.is_lost', $f['isLost']);
        }
    }

    public function getKpis(array $f): array
    {
        $bill = DB::table('purchase_bills as pb')
            ->when(true, fn ($q) => $this->applyBillFilters($q, $f))
            ->selectRaw('
                COUNT(pb.id)                          AS total_bills,
                COALESCE(SUM(pb.total_amount), 0)     AS total_purchase_value,
                COALESCE(SUM(pb.taxable_value), 0)    AS total_taxable_value,
                COALESCE(SUM(pb.total_tax), 0)        AS total_tax,
                COALESCE(SUM(pb.cgst_amount), 0)      AS total_cgst,
                COALESCE(SUM(pb.sgst_amount), 0)      AS total_sgst,
                COALESCE(SUM(pb.igst_amount), 0)      AS total_igst,
                COALESCE(SUM(pb.cess_amount), 0)      AS total_cess,
                SUM(pb.is_lost)                       AS lost_bills,
                SUM(pb.received)                      AS received_bills
            ')
            ->first();

        $lineStats = DB::table('purchase_bills as pb')
            ->join('purchase_lines as pl', 'pl.purchase_bill_id', '=', 'pb.id')
            ->when(true, fn ($q) => $this->applyBillFilters($q, $f))
            ->selectRaw('
                COALESCE(SUM(pl.qty), 0)              AS total_qty,
                COALESCE(SUM(pl.free_qty), 0)         AS total_free_qty,
                COUNT(DISTINCT pl.product_id)         AS unique_products
            ')
            ->first();

        return [
            'total_bills' => (int) $bill->total_bills,
            'total_purchase_value' => (float) $bill->total_purchase_value,
            'total_taxable_value' => (float) $bill->total_taxable_value,
            'total_tax' => (float) $bill->total_tax,
            'tax_breakdown' => [
                'cgst' => (float) $bill->total_cgst,
                'sgst' => (float) $bill->total_sgst,
                'igst' => (float) $bill->total_igst,
                'cess' => (float) $bill->total_cess,
            ],
            'lost_bills' => (int) $bill->lost_bills,
            'received_bills' => (int) $bill->received_bills,
            'total_qty' => (float) $lineStats->total_qty,
            'total_free_qty' => (float) $lineStats->total_free_qty,
            'unique_products' => (int) $lineStats->unique_products,
        ];
    }

    public function getBillTable(array $f): array
    {
        $rows = DB::table('purchase_bills as pb')
            ->leftJoin('suppliers as s', 's.id', '=', 'pb.supplier_id')
            ->when(true, fn ($q) => $this->applyBillFilters($q, $f))
            ->select([
                'pb.id',
                'pb.bill_no',
                'pb.bill_date',
                'pb.is_lost',
                'pb.received',
                'pb.taxable_value',
                'pb.total_tax',
                'pb.total_amount',
                'pb.cgst_amount',
                'pb.sgst_amount',
                'pb.igst_amount',
                'pb.cess_amount',
                's.name as supplier_name',
            ])
            ->orderByDesc('pb.bill_date')
            ->get();

        $totals = [
            'taxable_value' => $rows->sum('taxable_value'),
            'total_tax' => $rows->sum('total_tax'),
            'total_amount' => $rows->sum('total_amount'),
            'cgst_amount' => $rows->sum('cgst_amount'),
            'sgst_amount' => $rows->sum('sgst_amount'),
            'igst_amount' => $rows->sum('igst_amount'),
            'cess_amount' => $rows->sum('cess_amount'),
        ];

        return ['rows' => $rows, 'totals' => $totals];
    }

    public function getProductPerformance(array $f): array
    {
        $rows = DB::table('purchase_bills as pb')
            ->join('purchase_lines as pl', 'pl.purchase_bill_id', '=', 'pb.id')
            ->join('products as p', 'p.id', '=', 'pl.product_id')
            ->when(true, fn ($q) => $this->applyBillFilters($q, $f))
            ->groupBy('p.id', 'p.name', 'p.sku', 'p.hsn_code')
            ->selectRaw('
                p.id            AS product_id,
                p.name          AS product_name,
                p.sku,
                p.hsn_code,
                SUM(pl.qty)                           AS total_qty,
                SUM(pl.free_qty)                      AS total_free_qty,
                COALESCE(SUM(pl.amount), 0)           AS total_amount,
                COALESCE(SUM(pl.taxable_value), 0)    AS taxable_value,
                COALESCE(SUM(pl.total_gst), 0)        AS total_gst,
                AVG(pl.purchase_rate)                 AS avg_purchase_rate
            ')
            ->orderByRaw('SUM(pl.amount) DESC')
            ->get();

        $totals = [
            'total_qty' => $rows->sum('total_qty'),
            'total_amount' => $rows->sum('total_amount'),
            'taxable_value' => $rows->sum('taxable_value'),
            'total_gst' => $rows->sum('total_gst'),
        ];

        return ['rows' => $rows, 'totals' => $totals];
    }

    public function getSupplierBreakdown(array $f): array
    {
        $rows = DB::table('purchase_bills as pb')
            ->leftJoin('suppliers as s', 's.id', '=', 'pb.supplier_id')
            ->when(true, fn ($q) => $this->applyBillFilters($q, $f))
            ->groupBy('pb.supplier_id', 's.name')
            ->selectRaw('
                pb.supplier_id,
                COALESCE(s.name, "Unknown")           AS supplier_name,
                COUNT(pb.id)                          AS bill_count,
                COALESCE(SUM(pb.total_amount), 0)     AS total_amount,
                COALESCE(SUM(pb.total_tax), 0)        AS total_tax,
                COALESCE(SUM(pb.taxable_value), 0)    AS taxable_value
            ')
            ->orderByRaw('SUM(pb.total_amount) DESC')
            ->get();

        $grandTotal = $rows->sum('total_amount');

        $rows = $rows->map(function ($row) use ($grandTotal) {
            $row->share_pct = $grandTotal > 0
                ? round(($row->total_amount / $grandTotal) * 100, 1)
                : 0;

            return $row;
        });

        return ['rows' => $rows, 'grand_total' => $grandTotal];
    }
}
