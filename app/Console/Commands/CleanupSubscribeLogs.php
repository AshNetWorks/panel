<?php

namespace App\Console\Commands;

use App\Services\WatchNotifyService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Jobs\SendTelegramJob;
use Illuminate\Support\Facades\Log;

class CleanupSubscribeLogs extends Command
{
    /**
     * 命令签名
     */
    protected $signature = 'subscribe:cleanup 
                            {--days=30 : 保留最近多少天的日志} 
                            {--batch=500 : 每批删除的记录数} 
                            {--dry-run : 仅预览不执行删除}';

    /**
     * 命令描述
     */
    protected $description = '清理订阅拉取日志数据';

    /**
     * 执行命令
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $batchSize = (int) $this->option('batch');
        $dryRun = $this->option('dry-run');

        // 使用配置文件默认值
        if (!$this->hasOption('days') || !$this->option('days')) {
            $days = config('v2board.subscribe_log_cleanup.keep_days', 30);
        }

        if ($days < 1) {
            $this->error('保留天数必须大于0');
            Log::error('订阅日志清理失败：保留天数无效', ['days' => $days]);
            return 1;
        }

        $cutoffDate = Carbon::now()->subDays($days);

        // 监控名单用户永久保留，不参与清理
        $watchedUserIds = array_map('intval', array_keys(WatchNotifyService::getList()));

        $this->info("开始清理订阅拉取日志...");
        $this->info("保留天数: {$days} 天");
        $this->info("截止日期: " . $cutoffDate->format('Y-m-d H:i:s'));
        $this->info("批次大小: {$batchSize}");
        if (!empty($watchedUserIds)) {
            $this->info("🔒 跳过监控名单用户（共 " . count($watchedUserIds) . " 人），其记录永久保留");
        }

        if ($dryRun) {
            $this->warn("🔍 预览模式 - 不会实际删除数据");
        }

        try {
            // 统计待删除记录数（排除监控名单用户）
            $totalRecords = DB::table('v2_subscribe_pull_log')
                ->where('created_at', '<', $cutoffDate)
                ->when(!empty($watchedUserIds), fn($q) => $q->whereNotIn('user_id', $watchedUserIds))
                ->count();

            if ($totalRecords === 0) {
                $this->info("✅ 没有需要清理的记录");
                Log::info('订阅日志清理：无记录需要清理', [
                    'cutoff_date' => $cutoffDate->toDateTimeString(),
                    'timezone' => config('app.timezone')
                ]);
                return 0;
            }

            $this->info("📊 找到 {$totalRecords} 条需要清理的记录");

            if ($dryRun) {
                $this->showCleanupPreview($cutoffDate, $watchedUserIds);
                return 0;
            }

            // 修复非 UTF-8 字符（仅针对待删除记录，排除监控名单用户）
            $this->info("🔧 正在修复非 UTF-8 字符...");
            $excludeSql = !empty($watchedUserIds)
                ? ' AND user_id NOT IN (' . implode(',', $watchedUserIds) . ')'
                : '';
            DB::statement("
                UPDATE v2_subscribe_pull_log
                SET os = CONVERT(CAST(CONVERT(os USING latin1) AS BINARY) USING utf8mb4),
                    country = CONVERT(CAST(CONVERT(country USING latin1) AS BINARY) USING utf8mb4),
                    city = CONVERT(CAST(CONVERT(city USING latin1) AS BINARY) USING utf8mb4)
                WHERE created_at < ?{$excludeSql}
            ", [$cutoffDate]);
            $this->info("✅ 字符修复完成");

            // 执行删除
            $this->info("🗑️ 开始删除 {$totalRecords} 条记录...");
            $deletedTotal = 0;
            $progressBar = $this->output->createProgressBar($totalRecords);
            $progressBar->start();

            while (true) {
                $deleted = DB::table('v2_subscribe_pull_log')
                    ->where('created_at', '<', $cutoffDate)
                    ->when(!empty($watchedUserIds), fn($q) => $q->whereNotIn('user_id', $watchedUserIds))
                    ->limit($batchSize)
                    ->delete();

                if ($deleted === 0) {
                    break;
                }

                $deletedTotal += $deleted;
                $progressBar->advance($deleted);

                if ($deletedTotal % 10000 === 0) {
                    $this->line("");
                    $this->info("已删除 {$deletedTotal} 条记录...");
                }

                usleep(10000); // 10ms
            }

            $progressBar->finish();
            $this->line("");
            $this->info("✅ 清理完成！共删除 {$deletedTotal} 条记录");

            // 通知管理员
            $this->notifyAdmins($deletedTotal, $days);

            // 优化表
            if ($deletedTotal > 1000) {
                $this->info("🔧 正在优化数据表...");
                DB::statement('OPTIMIZE TABLE v2_subscribe_pull_log');
                $this->info("✅ 表优化完成");
            }

        } catch (\Exception $e) {
            $this->error("清理过程中发生错误: " . $e->getMessage());
            Log::error("订阅日志清理失败", [
                'error' => $e->getMessage(),
                'days' => $days,
                'cutoff_date' => $cutoffDate->toDateTimeString(),
                'timezone' => config('app.timezone')
            ]);
            return 1;
        }

        return 0;
    }

    /**
     * 显示清理预览信息
     */
    private function showCleanupPreview($cutoffDate, array $watchedUserIds = [])
    {
        $this->info("\n📋 清理预览信息:");

        // 按日期统计（排除监控名单用户）
        $dateStats = DB::table('v2_subscribe_pull_log')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->where('created_at', '<', $cutoffDate)
            ->when(!empty($watchedUserIds), fn($q) => $q->whereNotIn('user_id', $watchedUserIds))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'desc')
            ->limit(10)
            ->get();

        $this->table(['日期', '记录数'], $dateStats->map(function($item) {
            return [$item->date, number_format($item->count)];
        })->toArray());

        // 按用户统计（Top 10，排除监控名单用户）
        $userStats = DB::table('v2_subscribe_pull_log as log')
            ->leftJoin('v2_user as user', 'log.user_id', '=', 'user.id')
            ->select('user.email', DB::raw('COUNT(*) as count'))
            ->where('log.created_at', '<', $cutoffDate)
            ->when(!empty($watchedUserIds), fn($q) => $q->whereNotIn('log.user_id', $watchedUserIds))
            ->groupBy('user.email')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get();

        $this->info("\n👥 用户拉取统计 (Top 10):");
        $this->table(['用户邮箱', '拉取次数'], $userStats->map(function($item) {
            return [$item->email ?: '未知用户', number_format($item->count)];
        })->toArray());

        // 按操作系统统计（排除监控名单用户）
        $osStats = DB::table('v2_subscribe_pull_log')
            ->select('os', DB::raw('COUNT(*) as count'))
            ->where('created_at', '<', $cutoffDate)
            ->when(!empty($watchedUserIds), fn($q) => $q->whereNotIn('user_id', $watchedUserIds))
            ->groupBy('os')
            ->orderBy('count', 'desc')
            ->get();

        $this->info("\n💻 操作系统统计:");
        $this->table(['操作系统', '记录数'], $osStats->map(function($item) {
            return [$item->os, number_format($item->count)];
        })->toArray());

        // 异常字符检测（排除监控名单用户）
        $this->info("\n🔍 异常字符检测:");
        $invalidRecords = DB::table('v2_subscribe_pull_log')
            ->where('created_at', '<', $cutoffDate)
            ->when(!empty($watchedUserIds), fn($q) => $q->whereNotIn('user_id', $watchedUserIds))
            ->whereRaw('os REGEXP "[^[:print:]]" OR country REGEXP "[^[:print:]]" OR city REGEXP "[^[:print:]]"')
            ->select('id', 'os', 'country', 'city', 'created_at')
            ->limit(10)
            ->get();

        if ($invalidRecords->isEmpty()) {
            $this->info("✅ 未找到包含非打印字符的记录");
        } else {
            $this->table(['ID', '操作系统', '国家', '城市', '创建时间'], $invalidRecords->map(function($item) {
                return [$item->id, $item->os, $item->country, $item->city, $item->created_at];
            })->toArray());
        }
    }

    /**
     * 通知管理员
     */
    private function notifyAdmins($deletedCount, $days)
    {
        try {
            $adminTelegramId = $this->getSystemAdminTelegramId();
            if ($adminTelegramId) {
                $message = "🧹 订阅日志清理完成\n";
                $message .= "📅 保留天数：{$days} 天\n";
                $message .= "🗑️ 删除记录数：{$deletedCount}\n";
                $message .= "⏰ 完成时间：" . Carbon::now()->format('Y-m-d H:i:s') . "\n";
                $message .= "🌐 时区：" . config('app.timezone');
                SendTelegramJob::dispatch($adminTelegramId, $message);
                Log::info('清理完成通知已发送', [
                    'telegram_id' => $adminTelegramId,
                    'deleted_count' => $deletedCount
                ]);
            } else {
                Log::warning('未找到管理员 Telegram ID，清理通知未发送');
            }
        } catch (\Exception $e) {
            Log::error('清理通知发送失败', [
                'error' => $e->getMessage(),
                'deleted_count' => $deletedCount
            ]);
        }
    }

    /**
     * 获取管理员 Telegram ID
     */
    private function getSystemAdminTelegramId()
    {
        try {
            $adminUser = DB::table('v2_user')
                ->where('is_admin', 1)
                ->whereNotNull('telegram_id')
                ->where('banned', 0)
                ->first();

            if ($adminUser && $adminUser->telegram_id) {
                Log::info('从用户表获取到管理员 Telegram ID', [
                    'admin_email' => $adminUser->email,
                    'telegram_id' => $adminUser->telegram_id
                ]);
                return $adminUser->telegram_id;
            }

            $staffUser = DB::table('v2_user')
                ->where('is_staff', 1)
                ->whereNotNull('telegram_id')
                ->where('banned', 0)
                ->first();

            if ($staffUser && $staffUser->telegram_id) {
                Log::info('从用户表获取到 staff Telegram ID', [
                    'staff_email' => $staffUser->email,
                    'telegram_id' => $staffUser->telegram_id
                ]);
                return $staffUser->telegram_id;
            }

            Log::warning('未找到任何管理员或 staff 的 Telegram ID');
            return null;
        } catch (\Exception $e) {
            Log::error('获取管理员 Telegram ID 失败', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}