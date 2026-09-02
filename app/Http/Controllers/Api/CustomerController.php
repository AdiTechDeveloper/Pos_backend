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
    public function addAdvance(Request $request)
    {
        $request->validate([
            'customer.mobile' => 'required|string|max:15',
            'customer.name' => 'required|string|max:255',
            'customer.add1' => 'nullable|string|max:255',
            'customer.add2' => 'nullable|string|max:255',
            'customer.area' => 'nullable|string|max:255',
            'customer.city' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:1',
            'method' => 'required|in:cash,online',
            'transaction_id' => 'nullable|string',
        ]);

        $user = Auth::user();
        $branchId = $user->branches->pluck('id')->first();

        DB::beginTransaction();
        try {
            $customer = Customer::where('mobile', $request->customer['mobile'])->lockForUpdate()->first();

            if (! $customer) {
                $customer = Customer::create([
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

    public function walletBalance($mobile)
    {
        $customer = Customer::where('mobile', $mobile)->first();

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
            $deposits = CustomerAdvanceDeposit::with([
                'customer:id,name,mobile,opening_balance',
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
}
