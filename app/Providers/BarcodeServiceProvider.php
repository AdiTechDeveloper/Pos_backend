<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Milon\Barcode\BarcodeServiceProvider as MilonBarcodeProvider;

class BarcodeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->register(MilonBarcodeProvider::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
