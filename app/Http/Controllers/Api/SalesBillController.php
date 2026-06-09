<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\GstOutputLedger;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\SalesBill;
use App\Models\SalesBillLine;
use App\Models\SalesBillPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Milon\Barcode\Facades\DNS1DFacade as DNS1D;

class SalesBillController extends Controller
{
    public function scanBarcode(Request $request)
    {
        $request->validate(['barcode' => 'required']);

        $user = Auth::user();
        $branchIds = $user->branches->pluck('id')->toArray();

        // Find product by manufacturer barcode
        $product = Product::with('gstRate')
            ->where('barcode', $request->barcode)
            ->first();

        if (! $product) {
            return response()->json(['status' => false, 'message' => 'Product not found'], 404);
        }

        // Fetch all unique batches for this product in the user's branches
        $batches = Inventory::where('product_id', $product->id)
            ->whereIn('branch_id', $branchIds)
            ->whereColumn('sold_qty', '<', 'qty')
            ->select('id', 'batch_no', 'batch_barcode', 'mrp', 'cost_price', 'selling_price', 'expiry_date', 'qty', 'sold_qty')
            ->get()
            ->groupBy('batch_barcode')
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'inventory_id' => $first->id, // Reference ID for the store method
                    'batch_no' => $first->batch_no,
                    'batch_barcode' => $first->batch_barcode,
                    'mrp' => $first->mrp,
                    'selling_price' => $first->selling_price,
                    'cost_price' => $first->cost_price,
                    'expiry_date' => $first->expiry_date,
                    'total_stock' => $group->sum(fn ($i) => $i->qty - $i->sold_qty),
                ];
            })->values();

        if ($batches->isEmpty()) {
            return response()->json(['status' => false, 'message' => 'Product found but out of stock'], 404);
        }

        return response()->json([
            'status' => true,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'gst_rate' => $product->gstRate,
                'hsn_code' => $product->hsn_code,
                'is_gst_inclusive' => $product->gst_inclusive,
            ],
            'batches' => $batches,
        ]);
    }

    public function index()
    {
        $user = Auth::user();

        try {
            $query = SalesBill::with([
                'store',
                'branch',
                'user',
                'lines.product',
                'lines',
            ])->orderBy('id', 'desc');

            if ($user->role === 'cashier') {
                $query->where('user_id', $user->id);
            } elseif ($user->role === 'manager') {
                $branchIds = $user->branches->pluck('id')->toArray();

                if (! empty($branchIds)) {
                    $query->whereIn('branch_id', $branchIds);
                } else {
                    return response()->json([
                        'status' => false,
                        'message' => 'No branch assigned to this manager.',
                    ], 400);
                }
            }

            $bills = $query->get();

            return response()->json([
                'status' => true,
                'message' => 'Sales bills fetched successfully',
                'data' => $bills,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $user = Auth::user();

        try {
            $bill = SalesBill::with([
                'lines.product',
                'lines',
            ])->find($id);

            if (! $bill) {
                return response()->json([
                    'status' => false,
                    'message' => 'Sales bill not found.',
                ], 404);
            }

            if ($user->role === 'cashier') {
                if ($bill->user_id !== $user->id) {
                    return response()->json([
                        'status' => false,
                        'message' => 'You are not allowed to view this bill.',
                    ], 403);
                }
            } elseif ($user->role === 'manager') {
                $branchIds = $user->branches->pluck('id')->toArray();

                if (! in_array($bill->branch_id, $branchIds)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'You are not allowed to view bills from another branch.',
                    ], 403);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Sales bill fetched successfully',
                'data' => $bill,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey) {
            return response()->json(['error' => 'Missing Idempotency Key'], 400);
        }

        // Prevent duplicate bill creation
        $existing = SalesBill::where('last_idempotency_key_store', $idempotencyKey)->first();
        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'Duplicate Store Request Ignored',
                'data' => $existing,
            ], 200);
        }

        $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer',
            'lines.*.inventory_id' => 'required|integer', // From frontend popup
            'lines.*.qty' => 'required|numeric|min:0.01',

            'customer_id' => 'nullable|exists:customers,id',
            'customer' => 'nullable|array',
            'customer.name' => 'required_with:customer|string|max:255',
            'customer.mobile' => 'required_with:customer|string|max:15',
            'payment_type' => 'required|in:cash,online,split,credit',
            'cash_received' => 'nullable|numeric|min:0',
            'balance_return' => 'nullable|numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $branchId = $user->branches->pluck('id')->first();

            if (! $branchId) {
                return response()->json([
                    'status' => false,
                    'message' => 'User has no branch assigned.',
                ], 400);
            }

            // Start Bill Number Generation (Original Logic)
            $storeId = str_pad($user->store_id, 2, '0', STR_PAD_LEFT);
            $brId = str_pad($branchId, 2, '0', STR_PAD_LEFT);
            $counterId = str_pad($user->id, 2, '0', STR_PAD_LEFT);
            $date = now()->format('ymd');

            $todayCount = SalesBill::where('store_id', $user->store_id)
                ->where('branch_id', $branchId)
                ->whereDate('created_at', today())
                ->count();

            $seq = str_pad($todayCount + 1, 4, '0', STR_PAD_LEFT);
            $billNo = "{$storeId}{$brId}{$counterId}{$date}{$seq}";

            $bill = SalesBill::create([
                'store_id' => $user->store_id,
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'bill_no' => $billNo,
                'bill_status' => 'pending',
                'payment_status' => 'unpaid',
                'created_by' => $user->id,
                'last_idempotency_key_store' => $idempotencyKey,
                'customer_id' => $request->customer_id,
                'payment_type' => $request->payment_type,
            ]);

            $subtotal = 0;
            $totalGst = 0;
            $totalSaved = 0;
            $totalCogs = 0;
            $totalProfit = 0;
            $processedProducts = [];

            foreach ($request->lines as $lineData) {
                $product = Product::with('gstRate')->findOrFail($lineData['product_id']);

                // Get specific batch info from inventory table
                $selectedInventory = Inventory::where('id', $lineData['inventory_id'])
                    ->where('branch_id', $branchId)
                    ->firstOrFail();
                $batchBarcode = $selectedInventory->batch_barcode;
                $price = (float) $selectedInventory->selling_price;
                $mrp = (float) $selectedInventory->mrp;

                if ($selectedInventory->qty <= $selectedInventory->sold_qty) {
                    throw new \Exception('Stock already exhausted for this batch.');
                }

                if ($price <= 0) {
                    throw new \Exception("Invalid selling price for {$product->name} in this batch.");
                }

                $requiredQty = (float) $lineData['qty'];

                // Fetch all rows (Paid + Free) belonging to this specific batch
                $batchRows = Inventory::where('product_id', $product->id)
                    ->where('batch_barcode', $batchBarcode)
                    ->where('branch_id', $branchId)
                    ->whereColumn('sold_qty', '<', 'qty')
                    ->where(function ($q) {
                        $q->whereNull('expiry_date')
                            ->orWhere('expiry_date', '>=', now());
                    })
                    ->orderBy('free', 'asc')
                    ->lockForUpdate()
                    ->get();

                $availableStock = $batchRows->sum(fn ($inv) => $inv->qty - $inv->sold_qty);

                if ($availableStock < $requiredQty) {
                    throw new \Exception("Insufficient stock in batch {$selectedInventory->batch_no} for {$product->name}");
                }

                // COGS and Stock Deduction
                $remaining = $requiredQty;
                $totalLineCogs = 0;

                foreach ($batchRows as $batch) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $available = $batch->qty - $batch->sold_qty;
                    if ($available <= 0) {
                        continue;
                    }

                    $consume = min($available, $remaining);

                    // COGS Calculation (Same as your logic but per batch row)
                    $purchaseRate = (float) $batch->cost_price;
                    $gstRate = $product->gstRate->rate ?? 0;

                    if ($batch->purchase_gst_inclusive && $gstRate > 0) {
                        $purchaseRate = $purchaseRate * 100 / (100 + $product->gstRate->rate);
                    }

                    $totalLineCogs += ($purchaseRate * $consume);
                    $batch->increment('sold_qty', $consume);
                    $remaining -= $consume;
                }

                // Tax Logic (Original Functionality Preserved)
                $gstRate = $product->gstRate->rate ?? 0;
                if ($gstRate > 0) {
                    if ($product->gst_inclusive) {
                        $taxable = ($price * 100 / (100 + $gstRate)) * $requiredQty;
                        $totalLineGst = ($price * $requiredQty) - $taxable;
                    } else {
                        $taxable = $price * $requiredQty;
                        $totalLineGst = ($taxable * $gstRate) / 100;
                    }
                } else {
                    $taxable = $price * $requiredQty;
                    $totalLineGst = 0;
                }

                // State-based Tax Split
                $storeState = $user->store->state;
                $branchState = $user->branches->first()->state;
                $cgst = $sgst = $igst = 0;

                if ($gstRate > 0) {
                    if ($storeState == $branchState) {
                        $cgst = $sgst = $totalLineGst / 2;
                    } else {
                        $igst = $totalLineGst;
                    }
                }

                $lineAmount = $product->gst_inclusive ? ($price * $requiredQty) : ($taxable + $totalLineGst);

                $netRevenue = $taxable;
                $profit = $netRevenue - $totalLineCogs;

                // Record Line
                $salesLine = SalesBillLine::create([
                    'sales_bill_id' => $bill->id,
                    'product_id' => $product->id,
                    'branch_id' => $branchId,
                    'inventory_id' => $selectedInventory->id,
                    'qty' => $requiredQty,
                    'rate' => $price,
                    'taxable_amount' => $taxable,
                    'amount' => $lineAmount,
                    'cgst' => $cgst,
                    'sgst' => $sgst,
                    'igst' => $igst,
                    'total_gst' => $totalLineGst,
                    'cogs' => $totalLineCogs,
                    'profit' => $profit,
                ]);

                // GST Ledger Entry
                if ($totalLineGst > 0) {
                    GstOutputLedger::create([
                        'sales_bill_id' => $bill->id,
                        'sales_bill_line_id' => $salesLine->id,
                        'product_id' => $product->id,
                        'gst_rate_id' => $product->gst_rate_id,
                        'cgst' => $cgst,
                        'sgst' => $sgst,
                        'igst' => $igst,
                        'total_gst' => $totalLineGst,
                    ]);
                }

                $subtotal += $lineAmount;
                $totalGst += $totalLineGst;
                $totalCogs += $totalLineCogs;
                $totalProfit += ($taxable - $totalLineCogs);
                $totalSaved += ($mrp - $price) * $requiredQty;
                $processedProducts[] = $product->id;
            }

            // Final Bill Update
            $bill->update([
                'subtotal' => $subtotal,
                'total_gst' => $totalGst,
                'total_amount' => $subtotal,
                'total_saved' => $totalSaved,
                'total_cogs' => $totalCogs,
                'total_profit' => $totalProfit,
                'cash_received' => $request->payment_type === 'credit' ? 0 : ($request->cash_received ?? 0),
                'balance_return' => $request->payment_type === 'credit' ? 0 : ($request->balance_return ?? 0),
            ]);

            if ($request->payment_type === 'credit') {

                if ($request->customer) {
                    $customer = Customer::firstOrCreate(
                        ['mobile' => $request->customer['mobile']],
                        ['name' => $request->customer['name']]
                    );

                    $bill->customer_id = $customer->id;
                }

                $bill->paid_amount = 0;
                $bill->due_amount = $subtotal;
                $bill->payment_status = 'unpaid';

            } else {

                $bill->paid_amount = 0;
                $bill->due_amount = $subtotal;
                $bill->payment_status = 'unpaid';
            }

            $bill->save();

            DB::commit();

            return response()->json(['status' => true, 'message' => 'Sales bill created successfully', 'data' => $bill->load('lines')]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function pay(Request $request)
    {
        $request->validate([
            'sales_bill_id' => 'required|integer',
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|string',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.transaction_id' => 'nullable|string',
            'payments.*.gateway' => 'nullable|string',
            'payments.*.cash_received' => 'nullable|numeric|min:0',
            'payments.*.balance_return' => 'nullable|numeric|min:0',
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey) {
            return response()->json([
                'status' => false,
                'message' => 'Idempotency-Key header is required.',
            ], 400);
        }

        $bill = SalesBill::findOrFail($request->sales_bill_id);

        // prevent duplicate
        if ($bill->last_idempotency_key_payment === $idempotencyKey) {
            return response()->json([
                'status' => true,
                'message' => 'Duplicate request ignored. Returning previous result.',
                'bill' => $bill->load('payments'),
            ]);
        }

        DB::beginTransaction();

        try {

            $totalPaid = 0;
            $actualCashReceived = collect($request->payments)->sum('cash_received');

            foreach ($request->payments as $payment) {

                SalesBillPayment::create([
                    'sales_bill_id' => $bill->id,
                    'method' => $payment['method'],
                    'amount' => $payment['amount'],
                    'transaction_id' => $payment['transaction_id'] ?? null,
                    'gateway' => $payment['gateway'] ?? null,
                    'status' => 'success',
                ]);

                $totalPaid += $payment['amount'];
            }

            $bill->cash_received = $actualCashReceived;

            $bill->paid_amount = min($totalPaid, $bill->total_amount);
            $bill->due_amount = max($bill->total_amount - $bill->paid_amount, 0);

            if ($actualCashReceived > $bill->total_amount) {
                $bill->balance_return = $actualCashReceived - $bill->total_amount;
            } else {
                $bill->balance_return = 0;
            }

            // Payment status
            if (floatval($bill->due_amount) == 0) {
                $bill->payment_status = 'paid';
                $bill->bill_status = 'completed';
            } elseif ($bill->paid_amount > 0) {
                $bill->payment_status = 'partial';
            } else {
                $bill->payment_status = 'unpaid';
            }

            $bill->last_idempotency_key_payment = $idempotencyKey;
            $bill->save();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Payment recorded successfully',
                'bill' => $bill->load('payments'),
            ]);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function collectPayment(Request $request)
    {
        $request->validate([
            'sales_bill_id' => 'required|exists:sales_bills,id',
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string',
            'transaction_id' => 'nullable|string',
            'gateway' => 'nullable|string',
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey) {
            return response()->json([
                'status' => false,
                'message' => 'Idempotency-Key is required',
            ], 400);
        }

        DB::beginTransaction();

        try {
            $bill = SalesBill::lockForUpdate()->findOrFail($request->sales_bill_id);

            // Prevent duplicate request
            if ($bill->last_idempotency_key_payment === $idempotencyKey) {
                return response()->json([
                    'status' => true,
                    'message' => 'Duplicate request ignored',
                    'bill' => $bill->load('payments'),
                ]);
            }

            // Prevent overpayment
            if ($request->amount > $bill->due_amount) {
                throw new \Exception('Payment exceeds due amount');
            }

            // Save payment
            SalesBillPayment::create([
                'sales_bill_id' => $bill->id,
                'method' => $request->method,
                'amount' => $request->amount,
                'transaction_id' => $request->transaction_id,
                'gateway' => $request->gateway,
                'status' => 'success',
                'payment_phase' => 'collection', // KEY DIFFERENCE
            ]);

            $totalPaid = SalesBillPayment::where('sales_bill_id', $bill->id)
                ->where('status', 'success')
                ->sum('amount');

            $bill->paid_amount = $totalPaid;
            $bill->due_amount = $bill->total_amount - $totalPaid;
            $bill->cash_received = $bill->cash_received ?? 0;
            $bill->balance_return = $bill->balance_return ?? 0;

            // Update status
            if ($bill->due_amount == 0) {
                $bill->payment_status = 'paid';
                $bill->bill_status = 'completed';
            } elseif ($totalPaid > 0) {
                $bill->payment_status = 'partial';
            } else {
                $bill->payment_status = 'unpaid';
            }

            $bill->last_idempotency_key_payment = $idempotencyKey;
            $bill->save();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Payment collected successfully',
                'bill' => $bill->load('payments'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function customerWithDue()
    {
        $customers = SalesBill::select([
            'customer_id',
            DB::raw('SUM(due_amount) as total_due'),
        ])
            ->whereNotNull('customer_id')
            ->where('due_amount', '>', 0)
            ->groupBy('customer_id')
            ->with('customer:id,name,mobile')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $customers,
        ]);
    }

    public function customerDue(int $customerMobile)
    {
        $customer = Customer::where('mobile', $customerMobile)->first();

        if (! $customer) {
            return response()->json(['customer' => null]);
        }

        $totalDue = SalesBill::where('customer_id', $customer->id)
            ->where('due_amount', '>', 0)
            ->sum('due_amount');

        return response()->json([
            'customer' => $customer,
            'total_due' => $totalDue,
        ]);
    }

    public function getPrintData(Request $request)
    {
        $ids = $request->id; // array [1,2]

        if (! is_array($ids) || empty($ids)) {
            return response()->json([
                'status' => false,
                'message' => 'No bill IDs provided',
            ], 422);
        }

        $bills = SalesBill::with([
            'store',
            'branch',
            'user',
            'lines.product',
            'lines.inventory',
            'lines.gstRate',
        ])->whereIn('id', $ids)->get();

        if ($bills->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'Bills not found',
            ], 404);
        }
        // If you want multiple bills print data:
        $response = $bills->map(function ($bill) {
            $items = $bill->lines->map(function ($line) {
                $sellingPrice = $line->inventory->selling_price ?? $line->product->selling_price;
                $mrp = $line->inventory->mrp ?? $line->product->mrp;

                return [
                    'name' => $line->product->name,
                    'qty' => $line->qty,
                    'mrp' => round($mrp, 2),
                    'selling' => round($sellingPrice, 2),
                    'amount' => $line->amount,
                    'saved' => ($line->product->mrp - $line->product->selling_price) * $line->qty,
                    'cgst' => $line->cgst,
                    'sgst' => $line->sgst,
                    'igst' => $line->igst,
                    'cess' => $line->cess,
                    'gst_total' => $line->total_gst,
                ];
            });

            $barcode = 'data:image/png;base64,'.DNS1D::getBarcodePNG($bill->bill_no, 'C128', 3, 90);

            return [
                'store' => [
                    'name' => $bill->store->name,
                    'state' => $bill->store->state,
                    'phone' => $bill->store->phone,
                ],

                'branch' => [
                    'name' => $bill->branch->name,
                    'address' => $bill->branch->address,
                ],

                'bill' => [
                    'number' => $bill->bill_no,
                    'date' => $bill->created_at->setTimezone('Asia/Kolkata')->format('d-m-Y H:i'),
                    'cashier' => $bill->user->name,
                    'subtotal' => $bill->subtotal,
                    'total_gst' => $bill->total_gst,
                    'total_amount' => $bill->total_amount,
                    'total_saved' => $bill->total_saved,
                    'cgst_total' => $items->sum('cgst'),
                    'sgst_total' => $items->sum('sgst'),
                    'igst_total' => $items->sum('igst'),
                    'cess_total' => $items->sum('cess'),
                ],

                'items' => $items,
                'barcode' => $barcode,
                'footer' => 'Thank You! Visit Again',
            ];
        });

        return response()->json([
            'status' => true,
            'data' => $response,
        ]);
    }

    public function gstReport()
    {
        try {
            $user = Auth::user();

            $query = GstOutputLedger::with('bill');

            $branchId = $user->branches->pluck('id')->first();

            if ($user->role === 'manager') {
                $query->whereHas(
                    'bill',
                    fn ($q) => $q->where('branch_id', $branchId)
                );
            }

            if ($user->role === 'admin') {
                $query->whereHas(
                    'bill',
                    fn ($q) => $q->where('store_id', $user->store_id)
                );
            }

            $data = $query->orderBy('id', 'DESC')->get();

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
