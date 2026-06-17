<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinancialReportService;
use Illuminate\Http\Request;

class FinancialReportController extends Controller
{
    public function index(Request $request, FinancialReportService $service)
    {
        $data = $service->getFinancialReport($request);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }
}
