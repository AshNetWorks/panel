<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Jobs\SendTelegramJob;
use Carbon\Carbon;

class DailySubscribeReport extends Command
{
    protected $signature = 'report:daily-subscribe
                            {--date= : 指定报告日期(Y-m-d格式,默认昨天)}
                            {--admin-tg= : 指定管理员TG ID}
                            {--enhanced : 启用增强模式，包含更多统计信息}';

    protected $description = '生成每日订阅拉取报告并发送给管理员';

    public function handle()
    {
        // ✅ 确定报告日期（默认昨天）
        $reportDate = $this->option('date')
            ? Carbon::createFromFormat('Y-m-d', $this->option('date'))->startOfDay()
            : Carbon::yesterday()->startOfDay();

        // ✅ 统计时间范围（Unix 时间戳）
        $startTimestamp = $reportDate->copy()->startOfDay()->timestamp;
        $endTimestamp = $reportDate->copy()->endOfDay()->timestamp;

        // ✅ 对比时间范围（Unix 时间戳）
        $compareStartTimestamp = $reportDate->copy()->subDay()->startOfDay()->timestamp;
        $compareEndTimestamp = $reportDate->copy()->subDay()->endOfDay()->timestamp;

        $adminTelegramId = $this->option('admin-tg') ?: $this->getSystemAdminTelegramId();
        $enhanced = $this->option('enhanced');

        $this->info("📊 生成 {$reportDate->format('Y-m-d')} 的订阅拉取报告");
        $this->info("   统计范围: " . date('Y-m-d H:i:s', $startTimestamp) . " ~ " . date('Y-m-d H:i:s', $endTimestamp));
        $this->info("   对比范围: " . date('Y-m-d H:i:s', $compareStartTimestamp) . " ~ " . date('Y-m-d H:i:s', $compareEndTimestamp));

        try {
            // 生成报告数据
            $reportData = $this->generateReportData(
                $startTimestamp, $endTimestamp,
                $compareStartTimestamp, $compareEndTimestamp,
                $enhanced
            );

            // 构建消息
            $message = $enhanced
                ? $this->buildEnhancedMessage($reportData, $reportDate)
                : $this->buildSimpleMessage($reportData, $reportDate);

            // 发送报告
            if ($adminTelegramId) {
                $this->sendToAdmin($adminTelegramId, $message);
                $this->info("✅ 报告已发送到管理员 TG: {$adminTelegramId}");
            } else {
                if ($this->sendToAllAdmins($message)) {
                    $this->info("✅ 已发送给所有管理员");
                } else {
                    $this->warn("⚠️ 未找到管理员TG ID，报告未发送");
                    $this->line($message);
                }
            }

            // 记录日志
            $this->logReportActivity($reportData, $reportDate, $enhanced);
            return 0;

        } catch (\Exception $e) {
            $this->error("生成报告失败: " . $e->getMessage());
            \Log::error("每日订阅报告生成失败", [
                'date' => $reportDate->format('Y-m-d'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * 生成时间兼容的 SQL 条件
     * 兼容 created_at 为 Unix 时间戳（整数）或 datetime 字符串
     */
    private function getTimeCondition($startTimestamp, $endTimestamp)
    {
        // 如果 created_at 长度为10，说明是 Unix 时间戳；否则转换为时间戳
        $sqlTime = "IF(LENGTH(created_at)=10, created_at, UNIX_TIMESTAMP(created_at))";
        return "{$sqlTime} >= {$startTimestamp} AND {$sqlTime} <= {$endTimestamp}";
    }

    /**
     * 生成报告数据
     */
    private function generateReportData($startTimestamp, $endTimestamp, $compareStartTimestamp, $compareEndTimestamp, $enhanced = false)
    {
        $data = [];

        // 时间条件 SQL（兼容整数和字符串格式）
        $timeCondition = $this->getTimeCondition($startTimestamp, $endTimestamp);
        $compareTimeCondition = $this->getTimeCondition($compareStartTimestamp, $compareEndTimestamp);

        // 1. 基础统计（当日）
        $this->line("1/6 基础统计...");
        $data['basic'] = DB::table('v2_subscribe_pull_log')
            ->whereRaw($timeCondition)
            ->selectRaw('
                COUNT(*) as total_pulls,
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(DISTINCT ip) as unique_ips
            ')
            ->first();

        // 2. 对比统计（前一天）
        $this->line("2/6 对比统计...");
        $data['compare'] = DB::table('v2_subscribe_pull_log')
            ->whereRaw($compareTimeCondition)
            ->selectRaw('
                COUNT(*) as total_pulls,
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(DISTINCT ip) as unique_ips
            ')
            ->first();

        // 3. 操作系统统计
        $this->line("3/6 操作系统统计...");
        $data['top_os'] = DB::table('v2_subscribe_pull_log')
            ->whereRaw($timeCondition)
            ->whereNotNull('os')
            ->where('os', '!=', '')
            ->selectRaw('LEFT(TRIM(os), 30) as os_name, COUNT(*) as count')
            ->groupBy('os_name')
            ->orderBy('count', 'desc')
            ->limit(5)
            ->get();

        // 4. 地区统计
        $this->line("4/6 地区统计...");
        $data['top_countries'] = DB::table('v2_subscribe_pull_log')
            ->whereRaw($timeCondition)
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->selectRaw('LEFT(TRIM(country), 20) as country_name, COUNT(*) as count, COUNT(DISTINCT user_id) as users')
            ->groupBy('country_name')
            ->orderBy('count', 'desc')
            ->limit(5)
            ->get();

        // 增强模式额外统计
        if ($enhanced) {
            // 5. 按小时统计（需要处理时间格式）
            $this->line("5/6 时段统计...");
            $sqlHour = "IF(LENGTH(created_at)=10, HOUR(FROM_UNIXTIME(created_at)), HOUR(created_at))";
            $data['hourly'] = DB::table('v2_subscribe_pull_log')
                ->whereRaw($timeCondition)
                ->selectRaw("{$sqlHour} as hour, COUNT(*) as pulls")
                ->groupBy('hour')
                ->orderBy('hour')
                ->get()
                ->keyBy('hour');

            // 6. 活跃用户Top10
            $this->line("6/6 活跃用户统计...");
            $topUserStats = DB::table('v2_subscribe_pull_log')
                ->whereRaw($timeCondition)
                ->whereNotNull('user_id')
                ->selectRaw('user_id, COUNT(*) as pulls, COUNT(DISTINCT ip) as unique_ips')
                ->groupBy('user_id')
                ->orderBy('pulls', 'desc')
                ->limit(10)
                ->get();

            // 获取用户邮箱
            $userIds = $topUserStats->pluck('user_id')->toArray();
            $users = [];
            if (!empty($userIds)) {
                $users = DB::table('v2_user')
                    ->whereIn('id', $userIds)
                    ->pluck('email', 'id')
                    ->toArray();
            }

            $data['top_users'] = $topUserStats->map(function($item) use ($users) {
                return (object)[
                    'email' => $this->maskEmail($users[$item->user_id] ?? 'Unknown'),
                    'pulls' => $item->pulls,
                    'unique_ips' => $item->unique_ips
                ];
            });

            // 7. 异常IP检测（同一IP多用户使用）
            $data['suspicious_ips'] = DB::table('v2_subscribe_pull_log')
                ->whereRaw($timeCondition)
                ->whereNotNull('ip')
                ->selectRaw('
                    ip,
                    LEFT(COALESCE(country, "Unknown"), 15) as country_name,
                    COUNT(DISTINCT user_id) as unique_users,
                    COUNT(*) as total_pulls
                ')
                ->groupBy('ip', 'country_name')
                ->having('unique_users', '>', 3)
                ->orderBy('unique_users', 'desc')
                ->limit(5)
                ->get();

            // 8. 省份分布统计
            $data['top_provinces'] = DB::table('v2_subscribe_pull_log')
                ->whereRaw($timeCondition)
                ->whereNotNull('province')
                ->where('province', '!=', '')
                ->where('province', '!=', '未知')
                ->selectRaw('LEFT(TRIM(province), 20) as province_name, COUNT(*) as count, COUNT(DISTINCT user_id) as users')
                ->groupBy('province_name')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get();
        }

        $this->info("✅ 报告数据生成完成");
        return $data;
    }

    /**
     * 构建简单版消息
     */
    private function buildSimpleMessage($data, $reportDate)
    {
        $basic = $data['basic'];
        $compare = $data['compare'];

        // 计算变化
        $pullsChange = $basic->total_pulls - $compare->total_pulls;
        $usersChange = $basic->unique_users - $compare->unique_users;

        $message = "📊 *每日订阅拉取报告*\n";
        $message .= "📅 日期：`{$reportDate->format('Y年m月d日')}`\n";
        $message .= "━━━━━━━━━━━━━━━━━━\n\n";

        // 核心数据
        $message .= "📈 *核心数据*\n";
        $message .= "🔄 总拉取次数：`{$basic->total_pulls}`" . $this->formatChange($pullsChange) . "\n";
        $message .= "👥 活跃用户数：`{$basic->unique_users}`" . $this->formatChange($usersChange) . "\n";
        $message .= "🌐 独立IP数：`{$basic->unique_ips}`\n";

        if ($basic->unique_users > 0) {
            $avgPulls = round($basic->total_pulls / $basic->unique_users, 1);
            $message .= "📱 人均拉取：`{$avgPulls}`次\n";
        }
        $message .= "\n";

        // 操作系统
        if ($data['top_os']->isNotEmpty()) {
            $message .= "💻 *热门系统*\n";
            foreach ($data['top_os'] as $index => $os) {
                $message .= ($index + 1) . ". {$os->os_name}：{$os->count}次\n";
            }
            $message .= "\n";
        }

        // 地区分布
        if ($data['top_countries']->isNotEmpty()) {
            $message .= "🗺️ *热门地区*\n";
            foreach ($data['top_countries'] as $index => $country) {
                $message .= ($index + 1) . ". {$country->country_name}：{$country->count}次 ({$country->users}人)\n";
            }
            $message .= "\n";
        }

        $message .= "━━━━━━━━━━━━━━━━━━\n";
        $message .= "⏱️ 生成时间：" . Carbon::now()->format('Y-m-d H:i:s');

        return $message;
    }

    /**
     * 构建增强版消息
     */
    private function buildEnhancedMessage($data, $reportDate)
    {
        $basic = $data['basic'];
        $compare = $data['compare'];

        // 计算变化
        $pullsChange = $basic->total_pulls - $compare->total_pulls;
        $pullsPercent = $compare->total_pulls > 0
            ? round(($pullsChange / $compare->total_pulls) * 100, 1)
            : 0;

        $message = "📊 *每日订阅拉取报告 (增强版)*\n";
        $message .= "📅 日期：`{$reportDate->format('Y年m月d日')}`\n";
        $message .= "━━━━━━━━━━━━━━━━━━\n\n";

        // 核心数据
        $message .= "📈 *核心数据*\n";
        $message .= "🔄 总拉取次数：`{$basic->total_pulls}`";
        $message .= $this->formatChangeWithPercent($pullsChange, $pullsPercent) . "\n";
        $message .= "👥 活跃用户数：`{$basic->unique_users}`\n";
        $message .= "🌐 独立IP数：`{$basic->unique_ips}`\n";

        if ($basic->unique_users > 0) {
            $avgPulls = round($basic->total_pulls / $basic->unique_users, 1);
            $message .= "📱 人均拉取：`{$avgPulls}`次\n";
        }
        $message .= "\n";

        // 时段分析
        if (!empty($data['hourly']) && $data['hourly']->isNotEmpty()) {
            $message .= "⏰ *时段分析*\n";

            $peakHour = $data['hourly']->sortByDesc('pulls')->first();
            $lowHour = $data['hourly']->sortBy('pulls')->first();

            if ($peakHour) {
                $message .= "🔥 高峰时段：`{$peakHour->hour}:00` ({$peakHour->pulls}次)\n";
            }
            if ($lowHour) {
                $message .= "🌙 低峰时段：`{$lowHour->hour}:00` ({$lowHour->pulls}次)\n";
            }

            // 生成简化的24小时分布图
            $message .= "📊 分布：`";
            $maxPulls = $data['hourly']->max('pulls') ?: 1;
            for ($h = 0; $h < 24; $h += 3) {
                $hourData = $data['hourly']->get($h);
                $pulls = $hourData ? $hourData->pulls : 0;
                $bar = $this->getHourBar($pulls, $maxPulls);
                $message .= sprintf("%02d%s ", $h, $bar);
            }
            $message .= "`\n\n";
        }

        // 操作系统
        if ($data['top_os']->isNotEmpty()) {
            $message .= "💻 *操作系统 Top5*\n";
            foreach ($data['top_os'] as $index => $os) {
                $percent = $basic->total_pulls > 0
                    ? round(($os->count / $basic->total_pulls) * 100, 1)
                    : 0;
                $message .= ($index + 1) . ". {$os->os_name}：{$os->count}次 ({$percent}%)\n";
            }
            $message .= "\n";
        }

        // 地区分布
        if ($data['top_countries']->isNotEmpty()) {
            $message .= "🗺️ *地区分布 Top5*\n";
            foreach ($data['top_countries'] as $index => $country) {
                $message .= ($index + 1) . ". {$country->country_name}：{$country->count}次 ({$country->users}人)\n";
            }
            $message .= "\n";
        }

        // 省份分布（新增）
        if (!empty($data['top_provinces']) && $data['top_provinces']->isNotEmpty()) {
            $message .= "🏛️ *省份分布 Top10*\n";
            foreach ($data['top_provinces'] as $index => $province) {
                $message .= ($index + 1) . ". {$province->province_name}：{$province->count}次 ({$province->users}人)\n";
            }
            $message .= "\n";
        }

        // 活跃用户
        if (!empty($data['top_users']) && $data['top_users']->isNotEmpty()) {
            $message .= "🏆 *活跃用户 Top10*\n";
            foreach ($data['top_users'] as $index => $user) {
                $riskIcon = $user->unique_ips > 5 ? "⚠️" : "";
                $message .= ($index + 1) . ". {$user->email}{$riskIcon} - {$user->pulls}次/{$user->unique_ips}IP\n";
            }
            $message .= "\n";
        }

        // 异常IP检测
        if (!empty($data['suspicious_ips']) && $data['suspicious_ips']->isNotEmpty()) {
            $message .= "🚨 *异常IP检测*\n";
            foreach ($data['suspicious_ips'] as $ip) {
                $riskLevel = $ip->unique_users > 10 ? '🔴' : ($ip->unique_users > 5 ? '🟠' : '🟡');
                $message .= "{$riskLevel} {$ip->ip} ({$ip->country_name})\n";
                $message .= "   {$ip->unique_users}用户 | {$ip->total_pulls}次拉取\n";
            }
            $message .= "\n";
        }

        // 趋势分析
        $message .= "📈 *趋势分析*\n";
        if ($pullsPercent > 20) {
            $message .= "🚀 拉取量大幅增长 (+{$pullsPercent}%)\n";
        } elseif ($pullsPercent > 10) {
            $message .= "📈 拉取量稳步增长 (+{$pullsPercent}%)\n";
        } elseif ($pullsPercent < -20) {
            $message .= "📉 拉取量大幅下降 ({$pullsPercent}%)\n";
        } elseif ($pullsPercent < -10) {
            $message .= "⚠️ 拉取量有所下降 ({$pullsPercent}%)\n";
        } else {
            $message .= "📊 拉取量保持稳定\n";
        }

        $message .= "\n━━━━━━━━━━━━━━━━━━\n";
        $message .= "⏱️ 生成时间：" . Carbon::now()->format('Y-m-d H:i:s');

        return $message;
    }

    /**
     * 格式化变化值
     */
    private function formatChange($change)
    {
        if ($change > 0) {
            return " 📈+{$change}";
        } elseif ($change < 0) {
            return " 📉{$change}";
        }
        return "";
    }

    /**
     * 格式化变化值（带百分比）
     */
    private function formatChangeWithPercent($change, $percent)
    {
        if ($change > 0) {
            return " 📈+{$change} (+{$percent}%)";
        } elseif ($change < 0) {
            return " 📉{$change} ({$percent}%)";
        }
        return " ➖0";
    }

    /**
     * 获取小时柱状图字符
     */
    private function getHourBar($value, $max)
    {
        if ($max == 0) return '▁';
        $ratio = $value / $max;
        if ($ratio >= 0.8) return '█';
        if ($ratio >= 0.6) return '▆';
        if ($ratio >= 0.4) return '▄';
        if ($ratio >= 0.2) return '▂';
        return '▁';
    }

    /**
     * 邮箱脱敏
     */
    private function maskEmail($email)
    {
        if (!$email || $email === 'Unknown') {
            return '未知用户';
        }

        if (strpos($email, '@') === false) {
            return substr($email, 0, 8) . '***';
        }

        $parts = explode('@', $email);
        $username = $parts[0];
        $domain = $parts[1] ?? '';

        if (strlen($username) <= 2) {
            return $username . '***@' . $domain;
        }

        return substr($username, 0, 2) . '***@' . $domain;
    }

    /**
     * 获取系统管理员TG ID
     */
    private function getSystemAdminTelegramId()
    {
        return DB::table('v2_user')
            ->where('is_admin', 1)
            ->whereNotNull('telegram_id')
            ->where('banned', 0)
            ->value('telegram_id');
    }

    /**
     * 发送给指定管理员
     */
    private function sendToAdmin($telegramId, $message)
    {
        SendTelegramJob::dispatch($telegramId, $message);
        \Log::info("每日订阅报告已发送", [
            'telegram_id' => $telegramId,
            'date' => Carbon::now()->format('Y-m-d')
        ]);
    }

    /**
     * 发送给所有管理员
     */
    private function sendToAllAdmins($message)
    {
        $adminIds = DB::table('v2_user')
            ->where(function($query) {
                $query->where('is_admin', 1)->orWhere('is_staff', 1);
            })
            ->whereNotNull('telegram_id')
            ->where('banned', 0)
            ->pluck('telegram_id')
            ->toArray();

        if (empty($adminIds)) {
            return false;
        }

        foreach ($adminIds as $telegramId) {
            SendTelegramJob::dispatch($telegramId, $message);
        }

        return true;
    }

    /**
     * 记录报告活动日志
     */
    private function logReportActivity($data, $reportDate, $enhanced)
    {
        try {
            $logData = [
                'date' => $reportDate->format('Y-m-d'),
                'total_pulls' => $data['basic']->total_pulls ?? 0,
                'unique_users' => $data['basic']->unique_users ?? 0,
                'mode' => $enhanced ? 'enhanced' : 'simple',
                'generated_at' => Carbon::now()->toIso8601String()
            ];

            DB::table('v2_system_log')->insert([
                'action' => 'daily_subscribe_report',
                'description' => "每日订阅报告：{$reportDate->format('Y-m-d')}" . ($enhanced ? ' (增强版)' : ''),
                'data' => json_encode($logData, JSON_UNESCAPED_UNICODE),
                'created_at' => now()
            ]);
        } catch (\Exception $e) {
            \Log::warning("记录报告日志失败: " . $e->getMessage());
        }
    }
}
