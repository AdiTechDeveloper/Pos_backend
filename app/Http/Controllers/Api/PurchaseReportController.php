<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PurchaseReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseReportController extends Controller
{
    public function __construct(protected PurchaseReportService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'date_range' => 'nullable|string|in:today,yesterday,last_7_days,this_month,custom',
            'date_from' => 'nullable|date|required_if:date_range,custom',
            'date_to' => 'nullable|date|required_if:date_range,custom',
            'store_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
            'is_lost' => 'nullable|boolean',
        ]);

        $filters = $this->service->resolveFilters($request->only(
            'date_range', 'date_from', 'date_to',
            'store_id', 'branch_id', 'supplier_id', 'is_lost'
        ));

        return response()->json([
            'kpis' => $this->service->getKpis($filters),
            'bills' => $this->service->getBillTable($filters),
            'products' => $this->service->getProductPerformance($filters),
            'supplier_breakdown' => $this->service->getSupplierBreakdown($filters),
            'filters_applied' => $filters,
        ]);
    }
}
