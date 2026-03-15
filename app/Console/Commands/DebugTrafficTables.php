<?php

// 第一步：创建调试命令来检查数据库表结构
// 在 app/Console/Commands/ 目录下创建 DebugTrafficTables.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Carbon\Carbon;

class DebugTrafficTables extends Command
{
    protected $signature = 'debug:traffic-tables {user_email?}';
    protected $description = '调试流量统计表和数据';

    public function handle()
    {
        $this->info('=== 调试流量统计表和数据 ===');
        
        // 1. 检查所有可能的流量统计表
        $possibleTables = [
            'v2_server_stat',
            'v2_stat_user', 
            'v2_stat_server',
            'v2_user_traffic_log',
            'v2_server_traffic_log',
            'v2_traffic_log',
            'server_stat',
            'user_stat'
        ];
        
        $this->info('1. 检查数据库表:');
        $existingTables = [];
        
        foreach ($possibleTables as $table) {
            if (Schema::hasTable($table)) {
                $count = DB::table($table)->count();
                $columns = Schema::getColumnListing($table);
                $this->info("✅ {$table} (记录数: {$count})");
                $this->info("   字段: " . implode(', ', $columns));
                
                // 检查是否有今天的数据
                $today = Carbon::today();
                $todayCount = 0;
                
                if (in_array('record_at', $columns)) {
                    $todayCount = DB::table($table)->whereDate('record_at', $today)->count();
                } elseif (in_array('created_at', $columns)) {
                    $todayCount = DB::table($table)->whereDate('created_at', $today)->count();
                }
                
                $this->info("   今日记录数: {$todayCount}");
                $existingTables[] = $table;
                $this->line('');
            }
        }
        
        if (empty($existingTables)) {
            $this->error('❌ 未找到任何流量统计表！');
            return;
        }
        
        // 2. 检查用户数据
        $userEmail = $this->argument('user_email');
        if (!$userEmail) {
            $user = User::whereNotNull('telegram_id')->first();
            if (!$user) {
                $this->error('❌ 未找到绑定Telegram的用户');
                return;
            }
        } else {
            $user = User::where('email', $userEmail)->first();
            if (!$user) {
                $this->error("❌ 未找到用户: {$userEmail}");
                return;
            }
        }
        
        $this->info("2. 检查用户数据 (ID: {$user->id}, Email: {$user->email}):");
        $this->info("   上行流量: " . $this->formatBytes($user->u));
        $this->info("   下行流量: " . $this->formatBytes($user->d));
        $this->info("   总计流量: " . $this->formatBytes($user->u + $user->d));
        $this->info("   套餐流量: " . $this->formatBytes($user->transfer_enable));
        $this->line('');
        
        // 3. 在每个表中查找该用户的数据
        $this->info('3. 检查用户在各表中的流量记录:');
        $today = Carbon::today();
        
        foreach ($existingTables as $table) {
            $columns = Schema::getColumnListing($table);
            
            // 检查用户总记录数
            $userRecords = DB::table($table)->where('user_id', $user->id)->count();
            $this->info("   {$table}: 总记录数 {$userRecords}");
            
            if ($userRecords > 0) {
                // 检查今日记录
                $todayRecords = 0;
                $todayTraffic = 0;
                
                if (in_array('record_at', $columns)) {
                    $todayData = DB::table($table)
                        ->where('user_id', $user->id)
                        ->whereDate('record_at', $today)
                        ->selectRaw('COUNT(*) as count, SUM(COALESCE(u, 0) + COALESCE(d, 0)) as total_traffic')
                        ->first();
                    
                    $todayRecords = $todayData->count ?? 0;
                    $todayTraffic = $todayData->total_traffic ?? 0;
                    
                } elseif (in_array('created_at', $columns)) {
                    $todayData = DB::table($table)
                        ->where('user_id', $user->id)
                        ->whereDate('created_at', $today)
                        ->selectRaw('COUNT(*) as count, SUM(COALESCE(u, 0) + COALESCE(d, 0)) as total_traffic')
                        ->first();
                    
                    $todayRecords = $todayData->count ?? 0;
                    $todayTraffic = $todayData->total_traffic ?? 0;
                }
                
                $this->info("     今日记录数: {$todayRecords}");
                $this->info("     今日流量: " . $this->formatBytes($todayTraffic));
                
                // 显示最近几条记录作为示例
                $timeColumn = in_array('record_at', $columns) ? 'record_at' : 'created_at';
                if (in_array($timeColumn, $columns)) {
                    $recentRecords = DB::table($table)
                        ->where('user_id', $user->id)
                        ->orderBy($timeColumn, 'desc')
                        ->limit(3)
                        ->get();
                    
                    $this->info("     最近3条记录:");
                    foreach ($recentRecords as $record) {
                        $time = isset($record->$timeColumn) ? 
                            Carbon::parse($record->$timeColumn)->format('Y-m-d H:i:s') : 'N/A';
                        $u = $record->u ?? 0;
                        $d = $record->d ?? 0;
                        $total = $u + $d;
                        $this->info("       {$time}: " . $this->formatBytes($total) . " (上行: " . $this->formatBytes($u) . ", 下行: " . $this->formatBytes($d) . ")");
                    }
                }
            }
            $this->line('');
        }
        
        // 4. 测试修复后的方法
        $this->info('4. 测试获取今日流量使用:');
        $todayUsed = $this->getTodayTrafficUsageFixed($user);
        $this->info("   获取到的今日流量: " . $this->formatBytes($todayUsed));
        
        if ($todayUsed == 0) {
            $this->warn('⚠️  今日流量为0，可能的原因:');
            $this->warn('   1. 今天确实没有使用流量');
            $this->warn('   2. 流量统计表中没有今日数据');
            $this->warn('   3. 时间字段格式不匹配');
            $this->warn('   4. 统计方式与预期不同');
        }
    }
    
    // 修复后的获取今日流量方法
    private function getTodayTrafficUsageFixed(User $user)
    {
        $today = Carbon::today();
        $todayEnd = Carbon::tomorrow();
        
        // 方法1: v2_server_stat 表 (使用 record_at 字段)
        if (Schema::hasTable('v2_server_stat')) {
            $columns = Schema::getColumnListing('v2_server_stat');
            
            if (in_array('record_at', $columns)) {
                // 尝试 timestamp 格式
                $todayStats = DB::table('v2_server_stat')
                    ->where('user_id', $user->id)
                    ->where('record_at', '>=', $today->timestamp)
                    ->where('record_at', '<', $todayEnd->timestamp)
                    ->sum(DB::raw('COALESCE(u, 0) + COALESCE(d, 0)'));
                
                if ($todayStats > 0) {
                    $this->info("     从 v2_server_stat (timestamp) 获取: " . $this->formatBytes($todayStats));
                    return $todayStats;
                }
                
                // 尝试 datetime 格式
                $todayStats = DB::table('v2_server_stat')
                    ->where('user_id', $user->id)
                    ->whereDate('record_at', $today)
                    ->sum(DB::raw('COALESCE(u, 0) + COALESCE(d, 0)'));
                
                if ($todayStats > 0) {
                    $this->info("     从 v2_server_stat (date) 获取: " . $this->formatBytes($todayStats));
                    return $todayStats;
                }
            }
        }
        
        // 方法2: v2_stat_user 表
        if (Schema::hasTable('v2_stat_user')) {
            $columns = Schema::getColumnListing('v2_stat_user');
            
            if (in_array('record_at', $columns)) {
                $todayStats = DB::table('v2_stat_user')
                    ->where('user_id', $user->id)
                    ->whereDate('record_at', $today)
                    ->sum(DB::raw('COALESCE(u, 0) + COALESCE(d, 0)'));
                
                if ($todayStats > 0) {
                    $this->info("     从 v2_stat_user 获取: " . $this->formatBytes($todayStats));
                    return $todayStats;
                }
            }
        }
        
        // 方法3: 其他可能的表
        $otherTables = ['v2_stat_server', 'v2_user_traffic_log', 'v2_traffic_log'];
        foreach ($otherTables as $table) {
            if (Schema::hasTable($table)) {
                $columns = Schema::getColumnListing($table);
                $timeColumn = in_array('record_at', $columns) ? 'record_at' : 
                             (in_array('created_at', $columns) ? 'created_at' : null);
                
                if ($timeColumn && in_array('user_id', $columns)) {
                    $todayStats = DB::table($table)
                        ->where('user_id', $user->id)
                        ->whereDate($timeColumn, $today)
                        ->sum(DB::raw('COALESCE(u, 0) + COALESCE(d, 0)'));
                    
                    if ($todayStats > 0) {
                        $this->info("     从 {$table} 获取: " . $this->formatBytes($todayStats));
                        return $todayStats;
                    }
                }
            }
        }
        
        $this->warn('     所有方法都未获取到今日流量数据');
        return 0;
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