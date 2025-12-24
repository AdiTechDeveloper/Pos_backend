<?php

namespace App\Console\Commands;

use App\Models\PurchaseLine;
use App\Models\StockExpiryAlert;
use Carbon\Carbon;
use Illuminate\Console\Command;

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

        $this->info("Generating stock expiry alerts from {$today->toDateString()} to {$alertUntil->toDateString()}");

        $purchaseLines = PurchaseLine::query()
            ->whereNotNull('expiry_date')
            ->where('qty', '>', 0)
            ->whereBetween('expiry_date', [$today, $alertUntil])
            ->with('bill')
            ->get();

        if ($purchaseLines->isEmpty()) {
            $this->info('No expiring stock found.');
            return Command::SUCCESS;
        }

        foreach ($purchaseLines as $line) {
            $daysLeft = $today->diffInDays($line->expiry_date, false);

            $severity = match (true) {
                $daysLeft <= 0 => 'expired',
                $daysLeft <= 3 => 'danger',
                default => 'warning',
            };

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

            $this->info('Stock expiry alerts generated successfully.');

            return Command::SUCCESS;
        }
    }
}
