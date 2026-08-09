<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class NotifyNewOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:notify-new-order';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Executes the notifyNewOrder.js file (push + email admin on new orders)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $node_path = env('NODE_PATH', '');

        if (! empty($node_path)) {

            // Run the JS file using Node.js
            $command = $node_path . ' --no-experimental-fetch --max-old-space-size=1024 ' . storage_path('app/firebase/notifyNewOrder.js');
            \Log::info("Running command: " . $command);
            $output = shell_exec($command . " /dev/null 2>&1");

            \Log::info('Notify New Order Output: ' . $output);

            $this->info('Notify New Order process executed.');
        } else {

            // Log the output
            \Log::info('Notify New Order Output: Node path is not defined');
        }
    }
}
