<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAdvanceDeposit;
use App\Models\CustomerWalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $branchIds = $this->allowedBranchIds($user);

        $query = Customer::with('branch:id,name')->whereIn('branch_id', $branchIds);

        if ($user->role === 'admin' && $request->filled('branch_id')) {
            abort_unless(in_array((int) $request->branch_id, $branchIds, true), 403, 'Invalid branch');
            $query->where('branch_id', $request->branch_id);
        }

        return response()->json(['status' => true, 'data' => $query->latest()->get()]);
    }

    public function show(int $id)
    {
        $customer = Customer::with('branch:id,name')
            ->whereIn('branch_id', $this->allowedBranchIds(Auth::user()))
            ->find($id);

        if (! $customer) {
            return response()->json([
                'status' => false,
                'message' => 'Customer not found',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $customer,
        ]);
    }

    public function addAdvance(Request $request)
    {
        $request->validate([
            'customer.mobile' => 'required|string|max:15',
            'customer.name' => 'required|string|max:255',
            'customer.add1' => 'nullable|string|max:255',
            'customer.add2' => 'nullable|string|max:255',
            'customer.area' => 'nullable|string|max:255',
            'customer.city' => 'nullable|string|max:255',
            'branch_id' => 'nullable|integer',
            'amount' => 'required|numeric|min:1',
            'method' => 'required|in:cash,online',
            'transaction_id' => 'nullable|string',
        ]);

        $user = Auth::user();
        $branchId = $this->resolveBranchId($request->branch_id, $user);

        DB::beginTransaction();
        try {
            $customer = Customer::where('branch_id', $branchId)
                ->where('mobile', $request->customer['mobile'])
                ->lockForUpdate()
                ->first();

            if (! $customer) {
                $customer = Customer::create([
                    'branch_id' => $branchId,
                    'name' => $request->customer['name'],
                    'mobile' => $request->customer['mobile'],
                    'add1' => $request->customer['add1'] ?? null,
                    'add2' => $request->customer['add2'] ?? null,
                    'area' => $request->customer['area'] ?? null,
                    'city' => $request->customer['city'] ?? null,
                    'opening_balance' => 0,
                ]);

                $customer = Customer::where('id', $customer->id)->lockForUpdate()->first();
            }

            $deposit = CustomerAdvanceDeposit::create([
                'customer_id' => $customer->id,
                'branch_id' => $branchId,
                'amount' => $request->amount,
                'method' => $request->method,
                'transaction_id' => $request->transaction_id,
                'received_by' => $user->id,
            ]);

            $before = $customer->opening_balance;
            $customer->opening_balance += $request->amount;
            $customer->save();

            CustomerWalletTransaction::create([
                'customer_id' => $customer->id,
                'type' => 'credit',
                'amount' => $request->amount,
                'balance_before' => $before,
                'balance_after' => $customer->opening_balance,
                'source_type' => 'advance_deposit',
                'source_id' => $deposit->id,
                'created_by' => $user->id,
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Advance added successfully',
                'customer' => $customer,
                'balance' => $customer->opening_balance,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, int $id)
    {
        $user = Auth::user();
        if (! in_array($user->role, ['admin', 'manager'])) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $customerData = $request->input('customer', $request->all());
        $validated = validator($customerData, [
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:15'],
            'add1' => ['nullable', 'string', 'max:255'],
            'add2' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
        ])->validate();

        try {
            $customer = Customer::whereIn('branch_id', $this->allowedBranchIds($user))->find($id);

            if (! $customer) {
                return response()->json([
                    'status' => false,
                    'message' => 'Customer not found',
                ], 404);
            }

            $duplicate = Customer::where('branch_id', $customer->branch_id)
                ->where('mobile', $validated['mobile'])
                ->where('id', '!=', $customer->id)
                ->exists();

            if ($duplicate) {
                return response()->json(['status' => false, 'message' => 'Mobile already exists in this branch.'], 422);
            }

            $customer->update($validated);

            return response()->json([
                'status' => true,
                'message' => 'Customer updated successfully',
                'customer' => $customer,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while updating the customer details.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function walletBalance($mobile)
    {
        $customer = Customer::whereIn('branch_id', $this->allowedBranchIds(Auth::user()))
            ->where('mobile', $mobile)
            ->first();

        if (! $customer) {
            return response()->json(['customer' => null, 'balance' => 0]);
        }

        return response()->json([
            'customer' => $customer,
            'balance' => $customer->opening_balance,
        ]);
    }

    public function walletHistory($id)
    {
        $customerExists = Customer::whereIn('branch_id', $this->allowedBranchIds(Auth::user()))->whereKey($id)->exists();
        abort_unless($customerExists, 404, 'Customer not found');

        $transactions = CustomerWalletTransaction::where('customer_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $transactions,
        ]);
    }

    public function advanceReport(Request $request)
    {
        try {
            $deposits = CustomerAdvanceDeposit::whereHas('customer', function ($query) use ($request) {
                $user = Auth::user();
                $branchIds = $this->allowedBranchIds($user);
                $query->whereIn('branch_id', $branchIds);
                if ($user->role === 'admin' && $request->filled('branch_id')) {
                    $query->where('branch_id', $request->branch_id);
                }
            })->with([
                'customer:id,branch_id,name,mobile,opening_balance',
                'receivedBy:id,name',
                'branch:id,name',
            ])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $deposits,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function allowedBranchIds($user): array
    {
        if ($user->role === 'admin') {
            return $user->store?->branches()->pluck('id')->all() ?? [];
        }

        return $user->branches()->pluck('branches.id')->all();
    }

    private function resolveBranchId(?int $branchId, $user): int
    {
        $allowedBranchIds = $this->allowedBranchIds($user);
        $branchId = $branchId ?? ($user->branches()->first()?->id ?? ($allowedBranchIds[0] ?? null));

        abort_unless($branchId && in_array((int) $branchId, $allowedBranchIds, true), 403, 'Invalid branch');

        return (int) $branchId;
    }
}
