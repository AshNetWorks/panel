<?php

// 创建检查流量统计定时任务的命令
// app/Console/Commands/CheckTrafficSchedule.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\User;

class CheckTrafficSchedule extends Command
{
    protected $signature = 'check:traffic-schedule';
    protected $description = '检查流量统计定时任务和数据生成情况';

    public function handle()
    {
        $this->info('=== 检查流量统计系统 ===');
        
        // 1. 检查定时任务配置
        $this->info('1. 检查定时任务配置:');
        $this->checkScheduledTasks();
        
        // 2. 检查最近的流量数据生成情况
        $this->info('2. 检查流量数据生成情况:');
        $this->checkRecentTrafficData();
        
        // 3. 检查用户流量更新情况
        $this->info('3. 检查用户流量更新:');
        $this->checkUserTrafficUpdates();
        
        // 4. 提供解决方案建议
        $this->info('4. 解决方案建议:');
        $this->provideSolutions();
    }
    
    private function checkScheduledTasks()
    {
        try {
            // 检查Laravel调度器
            $this->line('检查Laravel调度器...');
            $this->call('schedule:list');
            
        } catch (\Exception $e) {
            $this->error('无法获取调度器信息: ' . $e->getMessage());
        }
    }
    
    private function checkRecentTrafficData()
    {
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();
        $weekAgo = Carbon::today()->subDays(7);
        
        // 检查 v2_stat_user 表的数据生成情况
        if (\Schema::hasTable('v2_stat_user')) {
            $this->line('v2_stat_user 表数据情况:');
            
            // 今日数据
            $todayCount = DB::table('v2_stat_user')->whereDate('record_at', $today)->count();
            $this->line("  今日记录数: {$todayCount}");
            
            // 昨日数据
            $yesterdayCount = DB::table('v2_stat_user')->whereDate('record_at', $yesterday)->count();
            $this->line("  昨日记录数: {$yesterdayCount}");
            
            // 最近7天数据
            $weekCount = DB::table('v2_stat_user')->where('record_at', '>=', $weekAgo)->count();
            $this->line("  最近7天记录数: {$weekCount}");
            
            // 最新记录时间
            $latestRecord = DB::table('v2_stat_user')->orderBy('record_at', 'desc')->first();
            if ($latestRecord) {
                $latestTime = Carbon::parse($latestRecord->record_at)->format('Y-m-d H:i:s');
                $this->line("  最新记录时间: {$latestTime}");
            }
            
            // 按日期统计最近7天的记录数
            $dailyStats = DB::table('v2_stat_user')
                ->where('record_at', '>=', $weekAgo)
                ->selectRaw('DATE(record_at) as date, COUNT(*) as count')
                ->groupBy('date')
                ->orderBy('date', 'desc')
                ->get();
            
            $this->line("  最近7天每日记录数:");
            foreach ($dailyStats as $stat) {
                $this->line("    {$stat->date}: {$stat->count} 条记录");
            }
        }
        
        $this->line('');
    }
    
    private function checkUserTrafficUpdates()
    {
        // 检查用户流量数据的更新情况
        $users = User::whereNotNull('telegram_id')->take(5)->get();
        
        $this->line('检查用户流量更新情况 (前5个绑定Telegram的用户):');
        
        foreach ($users as $user) {
            $this->line("用户: {$user->email} (ID: {$user->id})");
            $this->line("  当前总流量: " . $this->formatBytes($user->u + $user->d));
            $this->line("  更新时间: " . ($user->updated_at ? $user->updated_at->format('Y-m-d H:i:s') : 'N/A'));
            
            // 检查该用户在统计表中的最新记录
            if (\Schema::hasTable('v2_stat_user')) {
                $latestStat = DB::table('v2_stat_user')
                    ->where('user_id', $user->id)
                    ->orderBy('record_at', 'desc')
                    ->first();
                
                if ($latestStat) {
                    $statTime = Carbon::parse($latestStat->record_at)->format('Y-m-d H:i:s');
                    $statTraffic = $this->formatBytes(($latestStat->u ?? 0) + ($latestStat->d ?? 0));
                    $this->line("  最新统计: {$statTime} - {$statTraffic}");
                } else {
                    $this->line("  统计记录: 无");
                }
            }
            $this->line('');
        }
    }
    
    private function provideSolutions()
    {
        $this->line('基于检查结果的解决方案:');
        
        // 检查今日是否有流量统计数据
        $todayCount = 0;
        if (\Schema::hasTable('v2_stat_user')) {
            $todayCount = DB::table('v2_stat_user')->whereDate('record_at', Carbon::today())->count();
        }
        
        if ($todayCount == 0) {
            $this->warn('⚠️  今日没有流量统计数据，可能原因:');
            $this->line('1. 流量统计定时任务未运行或配置错误');
            $this->line('2. 系统时区设置问题');
            $this->line('3. 今天确实没有用户使用流量');
            $this->line('');
            
            $this->info('建议解决方案:');
            $this->line('1. 检查cron任务是否正常运行:');
            $this->line('   crontab -l | grep artisan');
            $this->line('');
            $this->line('2. 手动运行Laravel调度器:');
            $this->line('   php artisan schedule:run');
            $this->line('');
            $this->line('3. 检查是否有流量统计相关的命令:');
            $this->line('   php artisan list | grep -i stat');
            $this->line('   php artisan list | grep -i traffic');
            $this->line('');
            $this->line('4. 检查系统时区设置:');
            $this->line('   php artisan tinker --execute="echo \'当前时区: \' . config(\'app.timezone\'); echo \'\\n当前时间: \' . now();"');
            $this->line('');
        } else {
            $this->info('✅ 今日有流量统计数据，系统运行正常');
        }
        
        // 检查是否需要手动触发统计
        $this->info('5. 如果需要立即生成今日流量统计，可以尝试:');
        $this->line('   - 查看是否有统计命令: php artisan list | grep stat');
        $this->line('   - 手动运行调度器: php artisan schedule:run');
        $this->line('   - 检查队列处理: php artisan queue:work --once');
    }
    
    private function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}