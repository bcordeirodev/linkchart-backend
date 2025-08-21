<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class OptimizeApiCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api:optimize';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Optimize Laravel API for production (without views)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Optimizing Laravel API for production...');

        // Clear all caches first
        $this->info('🧹 Clearing all caches...');
        $this->call('config:clear');
        $this->call('route:clear');
        $this->call('cache:clear');

        // Cache optimizations (only what makes sense for API)
        $this->info('⚡ Caching configurations...');
        $this->call('config:cache');
        $this->call('route:cache');

        // Skip view:cache since this is an API
        $this->info('📋 Skipping view cache (API project)');

        // Queue optimization
        if (config('queue.default') !== 'sync') {
            $this->info('🔄 Restarting queue workers...');
            $this->call('queue:restart');
        }

        $this->info('✅ API optimization completed successfully!');

        return Command::SUCCESS;
    }
}
