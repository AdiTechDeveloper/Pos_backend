<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SalesReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesReportController extends Controller
{
    public function __construct(protected SalesReportService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'date_range' => 'nullable|string|in:today,yesterday,last_7_days,this_month,custom',
            'date_from' => 'nullable|date|required_if:date_range,custom',
            'date_to' => 'nullable|date|required_if:date_range,custom',
            'store_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'bill_status' => 'nullable|string|in:all,pending,completed,cancelled',
        ]);

        $filters = $this->service->resolveFilters($request->only(
            'date_range', 'date_from', 'date_to', 'store_id', 'branch_id', 'bill_status'
        ));

        return response()->json([
            'kpis' => $this->service->getKpis($filters),
            'invoices' => $this->service->getInvoiceTable($filters),
            'products' => $this->service->getProductPerformance($filters),
            'payment_methods' => $this->service->getPaymentMethods($filters),
            'price_overrides' => $this->service->getPriceOverrides($filters),
            'filters_applied' => $filters,
        ]);
    }
}
