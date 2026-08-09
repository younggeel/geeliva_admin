<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ConvertImagesToWebpCron extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:convert-images-webp';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Executes convertImagesToWebp.js --apply (auto webp + resize for newly added vendor/product images)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $node_path = env('NODE_PATH', '');

        if (! empty($node_path)) {

            // --apply: this command is the recurring cron sweep, not the manual one-off
            // run -- dry-run/--limit verification already happened by hand before this
            // was ever added to the schedule (see admin2 build notes).
            $command = $node_path . ' --no-experimental-fetch --max-old-space-size=1024 ' . storage_path('app/firebase/convertImagesToWebp.js') . ' --apply';
            \Log::info("Running command: " . $command);
            $output = shell_exec($command . " /dev/null 2>&1");

            \Log::info('Convert Images To Webp Output: ' . $output);

            $this->info('Convert Images To Webp process executed.');
        } else {

            // Log the output
            \Log::info('Convert Images To Webp Output: Node path is not defined');
        }
    }
}
