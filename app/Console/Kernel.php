<?php

namespace App\Console;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();
        $schedule->command('app:schedule-ride')->everyMinute();
        $schedule->command('app:schedule-on-demand-service')->daily();
        $schedule->command('app:send-scheduled-order-notification')->everyMinute();
        $schedule->command('app:auto-cancel-order')->everyMinute();
        $schedule->command('app:notify-new-order')->everyMinute();
        $schedule->command('app:notify-new-parcel')->everyMinute();
        $schedule->command('app:parcel-order-auto-cancel')->everyFiveMinutes();
        $schedule->command('app:rental-order-auto-cancel')->everyFiveMinutes();
        $schedule->command('app:convert-images-webp')->everyFiveMinutes();
        $schedule->command('app:expire-gift-orders')->hourly();
    }
    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
