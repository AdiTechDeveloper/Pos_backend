<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;
use Carbon\Carbon;

class DeleteExpiredTokens extends Command
{
    protected $signature = 'tokens:cleanup';
    protected $description = 'Delete tokens older than 2 days';

    public function handle()
    {
        $cutoff = Carbon::now()->subDays(2);

        $deleted = PersonalAccessToken::where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} expired tokens.");

        return 0;
    }
}
