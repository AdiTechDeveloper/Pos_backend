<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseLine;
use Illuminate\Support\Facades\Auth;

class PurchaseLineController extends Controller
{
    public function index()
    {
        try {
            $user = Auth::user();

            $purchaseLines = PurchaseLine::with('product', 'inventory')->get();

            return response()->json([
                'status' => true,
                'data' => $purchaseLines,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
