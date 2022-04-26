<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // 個人・仕事用カレンダーは30分毎に更新する
        $schedule->command('command:gcal-sync-notion')
            ->everyThirtyMinutes()
            ->timezone('Asia/Ho_Chi_Minh')
            ->between('6:00', '23:00');

        // 休日カレンダーは月1回だけ更新する
        $schedule->command('command:gcal-sync-notion holiday')
            ->monthly()
            ->timezone('Asia/Ho_Chi_Minh');
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
