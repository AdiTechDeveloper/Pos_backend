<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RegisterShift;
use App\Models\SalesBillPayment;
use App\Models\User;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        try {
            if (!in_array($request->user()->role, ['admin', 'manager'])) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $staff = User::where('store_id', $request->user()->store_id)
                ->whereIn('role', ['manager', 'cashier'])
                ->with('store', 'branches')
                ->get();

            return response()->json(['status' => true, 'data' => $staff], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Error fetching staff', 'error' => $e->getMessage()], 500);
        }
    }

    public function getRegisterStatus(Request $request)
    {
        $user = $request->user();

        $branchId = $request->query('branch_id') ?? $user->branches()->first()?->id;

        if (!$branchId) {
            return response()->json(['message' => 'No branch assigned to this user'], 400);
        }

        // Tied to the specific cashier who opened it — not shared across the branch.
        $activeShift = RegisterShift::where('branch_id', $branchId)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->exists();

        return response()->json(['active' => $activeShift]);
    }

   public function shiftHistory(Request $request)
{
    $user = $request->user();
    if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);
    if (!in_array($user->role, ['admin', 'manager']))
        return response()->json(['message' => 'Forbidden'], 403);

    $query = RegisterShift::with('user:id,name,username')
        ->where('status', 'closed')
        ->orderBy('closed_at', 'desc');

    if ($request->from_date)  $query->whereDate('opened_at', '>=', $request->from_date);
    if ($request->to_date)    $query->whereDate('opened_at', '<=', $request->to_date);
    if ($request->cashier_id) $query->where('user_id', $request->cashier_id);

    return response()->json(['data' => $query->get()]);
}

    public function openRegister(Request $request)
    {
        $request->validate(['opening_balance' => 'required|numeric']);

        $user = $request->user();
        $branchId = $request->query('branch_id') ?? $user->branches()->first()?->id;

        $alreadyOpen = RegisterShift::where('branch_id', $branchId)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->exists();

        if ($alreadyOpen) {
            return response()->json(['message' => 'A shift is already open for this user'], 409);
        }

        RegisterShift::create([
            'branch_id' => $branchId,
            'user_id' => $user->id,
            'opening_balance' => $request->opening_balance,
            'opened_at' => now(),
            'status' => 'open',
        ]);

        return response()->json(['message' => 'Register opened successfully']);
    }

    /**
     * Returns the cashier's currently open shift plus an expected closing
     * cash balance, calculated as: opening_balance + cash collected since
     * the shift opened. Only counts successful `cash` payments — `online`
     * and `later` payments never sit in the physical drawer, so they're
     * excluded from this number on purpose.
     */
    public function getShiftSummary(Request $request)
    {
        $user = $request->user();
        $user = $request->user();

if (!$user) {
    return response()->json(['message' => 'Unauthenticated'], 401);
}
        $branchId = $request->query('branch_id') ?? $user->branches()->first()?->id;

        $shift = RegisterShift::where('branch_id', $branchId)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        if (!$shift) {
            return response()->json(['message' => 'No open shift found for this user'], 404);
        }

       $cashCollected = SalesBillPayment::where('method', 'cash')
    ->where('status', 'success')
    ->whereDate('created_at', today())          // ← only today's payments
    ->whereHas('salesBill', function ($query) use ($branchId) {
        $query->where('branch_id', $branchId);
    })
    ->sum('amount');

        $openingBalance = (float) $shift->opening_balance;
        $expectedClosingBalance = $openingBalance + (float) $cashCollected;

        return response()->json([
            'shift_id' => $shift->id,
            'opened_at' => $shift->opened_at,
            'opening_balance' => $openingBalance,
            'cash_collected' => (float) $cashCollected,
            'expected_closing_balance' => $expectedClosingBalance,
        ]);
    }

   public function closeRegister(Request $request)
{
    $request->validate([
        'closing_balance'     => 'required|numeric',
        'other_expenses'      => 'nullable|numeric|min:0',
        'expense_description' => 'nullable|string|max:500',
    ]);

    $user     = $request->user();
    if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

    $branchId = $request->query('branch_id') ?? $user->branches()->first()?->id;
    if (!$branchId) return response()->json(['message' => 'No branch assigned'], 400);

    $shift = RegisterShift::where('branch_id', $branchId)
        ->where('user_id', $user->id)
        ->where('status', 'open')
        ->latest('opened_at')
        ->first();

    if (!$shift) {
        return response()->json(['message' => 'No open shift found for this user'], 404);
    }

    // Cash collected during THIS shift's window (opened_at -> now), not just "today".
    // This matters if a shift spans midnight or is closed late.
    $cashCollected = SalesBillPayment::where('method', 'cash')
        ->where('status', 'success')
        ->whereBetween('created_at', [$shift->opened_at, now()])
        ->whereHas('salesBill', function ($query) use ($branchId) {
            $query->where('branch_id', $branchId);
        })
        ->sum('amount');

    $otherExpenses           = (float) ($request->other_expenses ?? 0);
    $expectedClosingBalance  = (float) $shift->opening_balance + (float) $cashCollected - $otherExpenses;
    $discrepancy             = (float) $request->closing_balance - $expectedClosingBalance;

    $shift->update([
        'cash_collected'           => $cashCollected,
        'closing_balance'          => $request->closing_balance,
        'expected_closing_balance' => $expectedClosingBalance,
        'other_expenses'           => $otherExpenses,
        'expense_description'      => $request->expense_description,
        'discrepancy'              => $discrepancy,
        'closed_at'                => now(),
        'status'                   => 'closed',
    ]);

    return response()->json([
        'message'                  => 'Register closed successfully',
        'cash_collected'           => (float) $cashCollected,
        'expected_closing_balance' => $expectedClosingBalance,
        'actual_closing_balance'   => (float) $request->closing_balance,
        'other_expenses'           => $otherExpenses,
        'discrepancy'              => $discrepancy,
    ]);
}

    public function update(Request $request, $id)
    {
        try {
            $authUser = Auth::user();
            $staff = User::find($id);

            if (!$staff) return response()->json(['status' => false, 'message' => 'Staff not found'], 404);

            $validator = Validator::make($request->all(), [
                'role' => 'required|in:manager,cashier',
                'branch_ids' => 'required|array|min:1',
            ]);

            if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

            $data = $validator->validated();
            $staff->role = $data['role'];
            $staff->updated_by = $authUser->id;

            if ($data['role'] === 'cashier') {
                $staff->password = null;
            } else {
                $staff->pin_hash = null;
                if (!$staff->password) $staff->password = Hash::make('123456');
            }

            $staff->save();
            $staff->branches()->sync($data['branch_ids']);

            return response()->json(['status' => true, 'message' => 'Staff updated', 'data' => $staff->load('branches')], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $staff = User::find($id);
        if (!$staff) return response()->json(['message' => 'Staff not found'], 404);

        $staff->branches()->detach();
        $staff->delete();

        return response()->json(['status' => true, 'message' => 'Deleted successfully'], 200);
    }
}