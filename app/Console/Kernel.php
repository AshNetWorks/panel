<?php

namespace App\Console;

use App\Utils\CacheKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        // ✅ 注册清理订阅日志命令
        Commands\CleanupSubscribeLogs::class,
        Commands\DailySubscribeReport::class,  // 添加这一行
        Commands\CleanCheckinRecords::class,   // 注册签到清理命令
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());
        // traffic
        $schedule->command('traffic:update')->everyMinute()->withoutOverlapping();
        // v2board
        $schedule->command('v2board:statistics')->dailyAt('0:10');
        // check
        $schedule->command('check:order')->everyMinute()->withoutOverlapping();
        $schedule->command('check:commission')->everyFifteenMinutes();
        $schedule->command('check:ticket')->everyMinute();
        $schedule->command('check:renewal')->dailyAt('22:30');
        // reset
        $schedule->command('reset:traffic')->daily();
        $schedule->command('reset:log')->daily();
        // send
        $schedule->command('send:remindMail')->dailyAt('11:30');
        // horizon metrics
        $schedule->command('horizon:snapshot')->everyFiveMinutes();
        
        // 每日流量通知 - 每天晚上23点50分发送
        $schedule->command('telegram:daily-traffic')
                 ->dailyAt('23:50')
                 ->withoutOverlapping()
                 ->runInBackground();
        // ✅ 每日订阅拉取报告 - 凌晨0:15执行，统计昨天完整数据
        $schedule->command('report:daily-subscribe')
                 ->dailyAt('00:15')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->appendOutputTo(storage_path('logs/daily-subscribe-report.log'));
        // ✅ 修正后的订阅日志清理任务
        // 方案1：每天执行（推荐）- 只有当有60天前的数据时才会实际删除
        $schedule->command('subscribe:cleanup --days=30 --batch=1000')
                 ->dailyAt('02:00')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->appendOutputTo(storage_path('logs/subscribe-cleanup.log'));
        // ✅ 签到记录清理任务 - 每天凌晨3点清理30天前的签到记录
        $schedule->command('checkin:clean --days=30')
                 ->dailyAt('03:00')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->appendOutputTo(storage_path('logs/checkin-cleanup.log'));
        // ✅ 节点使用量日志清理任务 - 每天凌晨3:30清理30天前的记录
        $schedule->command('serverlog:clean --days=30')
                 ->dailyAt('03:30')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->appendOutputTo(storage_path('logs/serverlog-cleanup.log'));
        // ✅ 跨省订阅检测任务 - 每天凌晨4点和下午16点执行（24小时窗口，一天两次检测）
        $schedule->command('subscribe:detect-cross-province')
                 ->twiceDaily(4, 16)
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->appendOutputTo(storage_path('logs/cross-province-detect.log'));
        // ✅ 用户到期检查任务 - 每天上午9点和晚上21点检查（配置化）
        if (config('user_expire_check_enabled', false)) {
            $schedule->command('user:check-expired')
                     ->twiceDaily(9, 21)
                     ->withoutOverlapping()
                     ->runInBackground()
                     ->appendOutputTo(storage_path('logs/expired-users.log'));
        }
        // ✅ 到期预警通知 - 每天上午10点发送（到期前3天、1天各一次）
        $schedule->command('telegram:notify-expiring')
                 ->dailyAt('10:00')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->appendOutputTo(storage_path('logs/telegram-notify-expiring.log'));
        // ✅ 定时踢出过期用户 - 每天凌晨1点执行，宽限1小时，踢出时发送通知
        $schedule->command('telegram:kick-expired --grace=1 --notify')
                 ->dailyAt('01:00')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->appendOutputTo(storage_path('logs/telegram-kick-expired.log'));
        // Emby 相关任务（配置化调度）
        $this->scheduleEmbyTasks($schedule);
    }

    /**
     * 配置 Emby 相关定时任务（完全按配置执行）
     */
    private function scheduleEmbyTasks(Schedule $schedule): void
    {
        if (!config('emby.enabled', true)) {
            return;
        }

        // Emby 账户同步任务 - 配置化
        if (config('emby.sync.enabled', true)) {
            $intervalMinutes = (int)config('emby.sync.interval_minutes', 60);
            $logFile = storage_path('logs/' . config('emby.logging.log_file', 'emby-sync.log'));

            if ($intervalMinutes >= 60) {
                $hours = max(1, intval($intervalMinutes / 60));
                $schedule->command('emby:sync')
                    ->cron("0 */{$hours} * * *")
                    ->withoutOverlapping(10)
                    ->runInBackground()
                    ->appendOutputTo($logFile);
            } else {
                $schedule->command('emby:sync')
                    ->cron("*/{$intervalMinutes} * * * *")
                    ->withoutOverlapping(10)
                    ->runInBackground()
                    ->appendOutputTo($logFile);
            }
        }

        // Emby 日志清理任务 - 每日凌晨 2:30
        if (config('emby.logging.enabled', true)) {
            $schedule->call(function () {
                $this->cleanEmbyLogs();
            })->dailyAt('02:30')->name('emby-log-cleanup');
        }
    }

    /**
     * 清理 Emby 日志文件
     */
    private function cleanEmbyLogs(): void
    {
        try {
            $logFile   = storage_path('logs/' . config('emby.logging.log_file', 'emby-sync.log'));
            $maxSizeMB = (int)config('emby.logging.max_log_size_mb', 10);
            $keepLines = (int)config('emby.logging.keep_logs_lines', 1000);

            if (is_file($logFile)) {
                $fileSizeBytes = filesize($logFile);
                $maxSizeBytes  = $maxSizeMB * 1024 * 1024;

                if ($fileSizeBytes > $maxSizeBytes) {
                    $lines = file($logFile);
                    if ($lines && count($lines) > $keepLines) {
                        $tail = array_slice($lines, -$keepLines);
                        file_put_contents($logFile, implode('', $tail));

                        \Log::info('Emby 日志文件已清理', [
                            'original_size' => $fileSizeBytes,
                            'new_size'      => filesize($logFile),
                            'lines_kept'    => $keepLines
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::error('清理 Emby 日志失败: ' . $e->getMessage());
        }
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
