<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseLine;
use App\Models\StockExpiryAlert;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateStockExpiryAlerts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-stock-expiry-alerts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate daily alerts for expiring stock';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $today = Carbon::today();
        $alertUntil = Carbon::today()->addDays(10);

        $this->info(
            "Generating stock expiry alerts from {$today->toDateString()} to {$alertUntil->toDateString()}"
        );

        DB::transaction(function () use ($today, $alertUntil) {

            $purchaseLines = PurchaseLine::query()
                ->whereNotNull('expiry_date')
                ->where('qty', '>', 0)
                ->whereBetween('expiry_date', [$today, $alertUntil])
                ->with('bill')
                ->get();

            if ($purchaseLines->isEmpty()) {
                $this->info('No expiring stock found.');
                return;
            }

            foreach ($purchaseLines as $line) {

                $daysLeft = $today->diffInDays($line->expiry_date, false);

                $severity = match (true) {
                    $daysLeft <= 0 => 'expired',
                    $daysLeft <= 3 => 'danger',
                    default => 'warning',
                };

                // Create / update alert
                StockExpiryAlert::updateOrCreate(
                    [
                        'purchase_line_id' => $line->id,
                        'alert_date' => $today->toDateString(),
                    ],
                    [
                        'product_id' => $line->product_id,
                        'branch_id' => $line->bill->branch_id,
                        'expiry_date' => $line->expiry_date,
                        'days_left' => $daysLeft,
                        'severity' => $severity,
                    ]
                );

                // Only process stock when actually expired
                if ($severity !== 'expired') {
                    continue;
                }

                // Fetch ALL inventory rows of SAME product + SAME batch
                $inventories = Inventory::where('product_id', $line->product_id)
                    ->where('batch_no', $line->batch_no)
                    ->whereNull('expired_at') // avoid double expiry
                    ->lockForUpdate()
                    ->get();

                if ($inventories->isEmpty()) {
                    continue;
                }

                $totalExpiredQty = 0;

                foreach ($inventories as $inv) {

                    $availableQty =
                        $inv->qty
                        - $inv->sold_qty
                        - $inv->expired_qty;

                    if ($availableQty <= 0) {
                        continue;
                    }

                    // Mark inventory expired
                    $inv->update([
                        'expired_qty' => $inv->expired_qty + $availableQty,
                        'expired_at' => now(),
                    ]);

                    $totalExpiredQty += $availableQty;
                }

                // Reduce product stock ONCE per batch
                if ($totalExpiredQty > 0) {
                    Product::where('id', $line->product_id)
                        ->decrement('stock', $totalExpiredQty);
                }
            }
        });

        $this->info('Stock expiry alerts generated successfully.');

        return Command::SUCCESS;
    }
}
