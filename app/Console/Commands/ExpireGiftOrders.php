<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ExpireGiftOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:expire-gift-orders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Executes the expireGiftOrders.js file';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $node_path = env('NODE_PATH', '');

        if (! empty($node_path)) {

            // Run the JS file using Node.js
            $command = $node_path . ' --no-experimental-fetch --max-old-space-size=1024 ' . storage_path('app/firebase/expireGiftOrders.js');
            \Log::info("Running command: " . $command);
            $output = shell_exec($command . " /dev/null 2>&1");

            \Log::info('Expire Gift Orders Output: ' . $output);

            $this->info('Expire gift orders process executed.');
        } else {

            // Log the output
            \Log::info('Expire Gift Orders Output: Node path is not defined');
        }
    }
}
