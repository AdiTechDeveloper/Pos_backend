<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockExpiryAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StockExpiryController extends Controller
{
    public function stockExpiryAlerts(Request $request)
    {
        $user = Auth::user();

        $query = StockExpiryAlert::with([
            'product:id,name',
            'branch:id,name',
            'purchaseLine:id,batch_no,qty'
        ])
            ->where('expiry_date', '>=', today())
            ->whereDate('alert_date', today())
            ->orderByRaw("FIELD(severity, 'expired', 'danger', 'warning')")
            ->orderBy('days_left');

        if ($user->role === 'manager') {
            $branchIds = $user->branches()->pluck('branches.id');
            $query->whereIn('branch_id', $branchIds);
        }

        $alerts = $query->get();

        return response()->json([
            'total' => $alerts->count(),
            'alerts' => $alerts->map(function ($a) {
                return [
                    'id' => $a->id,
                    'product_name' => $a->product->name ?? '-',
                    'batch_no' => $a->purchaseLine->batch_no ?? '-',
                    'qty' => $a->purchaseLine->qty ?? 0,
                    'expiry_date' => $a->expiry_date,
                    'days_left' => $a->days_left,
                    'severity' => $a->severity,
                    'branch_name' => $a->branch->name ?? '-',
                    'alert_date' => $a->alert_date,
                ];
            }),
        ]);
    }
}
