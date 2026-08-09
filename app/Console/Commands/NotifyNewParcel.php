<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class NotifyNewParcel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:notify-new-parcel';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Executes the notifyNewParcel.js file (push + email admin on new parcel orders)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $node_path = env('NODE_PATH', '');

        if (! empty($node_path)) {

            // Run the JS file using Node.js
            $command = $node_path . ' --no-experimental-fetch --max-old-space-size=1024 ' . storage_path('app/firebase/notifyNewParcel.js');
            \Log::info("Running command: " . $command);
            $output = shell_exec($command . " /dev/null 2>&1");

            \Log::info('Notify New Parcel Output: ' . $output);

            $this->info('Notify New Parcel process executed.');
        } else {

            // Log the output
            \Log::info('Notify New Parcel Output: Node path is not defined');
        }
    }
}
