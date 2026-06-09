<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceOverride;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PriceOverrideController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = Auth::user();

            $query = PriceOverride::with([
                'product:id,name',
                'bill:id,bill_no',
                'overriddenBy:id,name',
                'branch:id,name',
            ]);

            // Scope by store
            if ($user->role === 'admin') {
                $query->whereHas('bill', function ($q) use ($user) {
                    $q->where('store_id', $user->store_id);
                });
            } elseif ($user->role === 'manager') {
                $branchIds = $user->branches->pluck('id')->toArray();
                $query->whereIn('branch_id', $branchIds);
            }

            // Filters
            if ($request->filled('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }

            if ($request->filled('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            if ($request->filled('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            if ($request->filled('overridden_by')) {
                $query->where('overridden_by', $request->overridden_by);
            }

            if ($request->filled('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            $records = $query->orderBy('id', 'DESC')->get();

            $totalLoss = $records->sum('total_loss');

            return response()->json([
                'status'     => true,
                'data'       => $records,
                'total_loss' => round($totalLoss, 2),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}