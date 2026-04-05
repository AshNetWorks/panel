<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use App\Services\TelegramService;
use Carbon\Carbon;

class DailySubscribeReport extends Command
{
    protected $signature = 'report:daily-subscribe {--date= : 指定报告日期(Y-m-d格式，默认昨天)}';
    protected $description = '每日订阅安全巡检报告';

    public function handle()
    {
        $reportDate = $this->option('date')
            ? Carbon::createFromFormat('Y-m-d', $this->option('date'))->startOfDay()
            : Carbon::yesterday()->startOfDay();

        $start = $reportDate->copy()->startOfDay();
        $end   = $reportDate->copy()->endOfDay();

        $this->info("生成 {$reportDate->format('Y-m-d')} 安全巡检报告...");

        try {
            $message = $this->buildSecurityReport($start, $end, $reportDate);
            $this->sendToAllAdmins($message, $reportDate);
            $this->info('✅ 报告已发送');
            return 0;
        } catch (\Exception $e) {
            $this->error('生成失败: ' . $e->getMessage());
            \Illuminate\Support\Facades\Log::error('每日订阅安全报告失败', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return 1;
        }
    }

    private function buildSecurityReport(Carbon $start, Carbon $end, Carbon $reportDate): string
    {
        $alerts = [];
        $lines  = [];

        // ── 基础概览 ──────────────────────────────────────────
        $overview = DB::table('v2_subscribe_pull_log')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('COUNT(*) as total, COUNT(DISTINCT user_id) as users, COUNT(DISTINCT ip) as ips, SUM(blocked) as blocked')
            ->first();

        $lines[] = "📋 *概览*";
        $lines[] = "拉取总次数：{$overview->total}　活跃用户：{$overview->users}　独立IP：{$overview->ips}";
        if ((int)($overview->blocked ?? 0) > 0) {
            $lines[] = "🚫 被拦截请求：{$overview->blocked} 次";
        }

        // ── 封禁动态 ──────────────────────────────────────────
        $newBans = DB::table('v2_subscribe_pull_log')
            ->whereBetween('created_at', [$start, $end])
            ->where('blocked', 1)
            ->whereIn('block_reason', ['ip_limit', 'rate_limit'])
            ->selectRaw('block_reason, COUNT(DISTINCT user_id) as cnt')
            ->groupBy('block_reason')
            ->get()
            ->keyBy('block_reason');

        $currentlyBanned = $this->countCurrentlyBanned();

        $lines[] = "";
        $lines[] = "🔒 *封禁动态*";
        $ipBanCnt   = $newBans->get('ip_limit')->cnt   ?? 0;
        $rateBanCnt = $newBans->get('rate_limit')->cnt ?? 0;
        if ($ipBanCnt || $rateBanCnt) {
            $lines[] = "今日新增封禁：IP超限 {$ipBanCnt} 人 / 频率超限 {$rateBanCnt} 人";
            if ($ipBanCnt + $rateBanCnt >= 5) {
                $alerts[] = "⚠️ 今日封禁人数较多（" . ($ipBanCnt + $rateBanCnt) . " 人），请关注是否有订阅泄露";
            }
        } else {
            $lines[] = "今日无新增封禁";
        }
        $lines[] = "当前仍封禁中：{$currentlyBanned} 人";

        $selfUnban = DB::table('v2_subscribe_unban_log')
            ->whereBetween('created_at', [$start, $end])
            ->count();
        if ($selfUnban > 0) {
            $lines[] = "今日自助解封：{$selfUnban} 次";
        }

        // ── 高风险用户（疑似分享订阅）──────────────────────────
        $ipLimit       = (int)config('v2board.sub_ip_limit_count', 10);
        $warnThreshold = max(1, $ipLimit - 2);

        $highRiskUsers = DB::table('v2_subscribe_pull_log')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('user_id, COUNT(*) as pulls, COUNT(DISTINCT ip) as unique_ips')
            ->groupBy('user_id')
            ->having('unique_ips', '>=', $warnThreshold)
            ->orderByDesc('unique_ips')
            ->limit(10)
            ->get();

        if ($highRiskUsers->isNotEmpty()) {
            $userIds = $highRiskUsers->pluck('user_id')->toArray();
            $emails  = DB::table('v2_user')->whereIn('id', $userIds)->pluck('email', 'id');

            $lines[] = "";
            $lines[] = "🚨 *高风险用户（独立IP接近/超限，疑似分享订阅）*";
            foreach ($highRiskUsers as $u) {
                $email    = $emails[$u->user_id] ?? "uid:{$u->user_id}";
                $isBanned = Redis::exists('sub:banned:' . $u->user_id) ? ' 🔴已封' : '';
                $riskTag  = $u->unique_ips >= $ipLimit ? '🔴' : '🟠';
                $lines[]  = "{$riskTag} {$email}　{$u->unique_ips}/{$ipLimit} IP　{$u->pulls}次{$isBanned}";
            }

            if ($highRiskUsers->count() >= 3) {
                $alerts[] = "🚨 有 {$highRiskUsers->count()} 名用户独立IP达到高风险阈值，请重点核查";
            }
        }

        // ── 共享IP（最直接的泄露信号）──────────────────────────
        $sharedIps = DB::table('v2_subscribe_pull_log')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('ip')
            ->selectRaw('ip, COUNT(DISTINCT user_id) as user_cnt, COUNT(*) as pulls, MAX(country) as country, MAX(isp) as isp')
            ->groupBy('ip')
            ->having('user_cnt', '>=', 2)
            ->orderByDesc('user_cnt')
            ->limit(10)
            ->get();

        if ($sharedIps->isNotEmpty()) {
            $lines[] = "";
            $lines[] = "🔍 *共享IP检测（同一IP多用户，强烈疑似订阅泄露）*";
            foreach ($sharedIps as $row) {
                $location = trim(($row->country ?? '') . ' ' . ($row->isp ?? ''));
                $riskTag  = $row->user_cnt >= 5 ? '🔴' : ($row->user_cnt >= 3 ? '🟠' : '🟡');
                $lines[]  = "{$riskTag} {$row->ip}　{$row->user_cnt} 用户 / {$row->pulls} 次" .
                            ($location ? "　{$location}" : '');

                $ipUsers = DB::table('v2_subscribe_pull_log as l')
                    ->join('v2_user as u', 'u.id', '=', 'l.user_id')
                    ->whereBetween('l.created_at', [$start, $end])
                    ->where('l.ip', $row->ip)
                    ->selectRaw('u.email')
                    ->distinct()
                    ->limit(5)
                    ->pluck('email');

                foreach ($ipUsers as $email) {
                    $lines[] = "　　· {$email}";
                }
            }

            if ($sharedIps->where('user_cnt', '>=', 3)->count() > 0) {
                $alerts[] = "🔴 发现 {$sharedIps->count()} 个IP被多人共用，存在订阅链接泄露风险";
            }
        }

        // ── 境外访问 ──────────────────────────────────────────
        $foreignAccess = DB::table('v2_subscribe_pull_log')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('country')
            ->where('country', '!=', '中国')
            ->where('country', '!=', '')
            ->selectRaw('country, COUNT(DISTINCT user_id) as users, COUNT(*) as pulls')
            ->groupBy('country')
            ->orderByDesc('users')
            ->limit(5)
            ->get();

        if ($foreignAccess->isNotEmpty()) {
            $lines[] = "";
            $lines[] = "🌍 *境外访问分布*";
            foreach ($foreignAccess as $row) {
                $lines[] = "· {$row->country}　{$row->users} 用户 / {$row->pulls} 次";
            }
        }

        // ── 浏览器直接访问 ──────────────────────────────────────
        $browserAccess = DB::table('v2_subscribe_pull_log')
            ->whereBetween('created_at', [$start, $end])
            ->where(function ($q) {
                $q->where('os', 'like', 'Chrome%')
                  ->orWhere('os', 'like', 'Safari%')
                  ->orWhere('os', 'like', 'Firefox%')
                  ->orWhere('os', 'like', 'Edge%');
            })
            ->selectRaw('COUNT(DISTINCT user_id) as users, COUNT(*) as pulls')
            ->first();

        if ($browserAccess && $browserAccess->pulls > 0) {
            $lines[] = "";
            $lines[] = "🌐 *浏览器直接访问订阅链接*";
            $lines[] = "{$browserAccess->users} 人 / {$browserAccess->pulls} 次（注意是否截图分享）";
            if ($browserAccess->users >= 3) {
                $alerts[] = "⚠️ {$browserAccess->users} 人通过浏览器查看了订阅链接，存在截图泄露风险";
            }
        }

        // ── 拼接 ──────────────────────────────────────────────
        $header = "🛡 *订阅安全日报 · {$reportDate->format('Y-m-d')}*\n" .
                  "━━━━━━━━━━━━━━━━━━\n";

        $alertBlock = '';
        if (!empty($alerts)) {
            $alertBlock = "\n❗ *今日重点提示*\n" . implode("\n", $alerts) . "\n\n━━━━━━━━━━━━━━━━━━\n";
        }

        $footer = "\n━━━━━━━━━━━━━━━━━━\n⏱ " . Carbon::now()->format('Y-m-d H:i:s');

        return $header . $alertBlock . implode("\n", $lines) . $footer;
    }

    /**
     * 按 \n\n 段落切分，每页不超过 3500 字符
     */
    private function paginate(string $content, int $maxLen = 3500): array
    {
        if (mb_strlen($content) <= $maxLen) {
            return [$content];
        }

        $sections = explode("\n\n", $content);
        $pages    = [];
        $current  = '';

        foreach ($sections as $section) {
            $candidate = $current === '' ? $section : $current . "\n\n" . $section;
            if (mb_strlen($candidate) > $maxLen) {
                if ($current !== '') {
                    $pages[] = $current;
                }
                $current = $section;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $pages[] = $current;
        }

        return $pages ?: [$content];
    }

    /**
     * 构建翻页键盘
     */
    private function buildPageKeyboard(string $cacheKey, int $current, int $total): array
    {
        $buttons = [];
        if ($current > 0) {
            $buttons[] = ['text' => '◀ 上一页', 'callback_data' => "rpt_page:{$cacheKey}:{$current}:prev"];
        }
        $buttons[] = ['text' => ($current + 1) . ' / ' . $total, 'callback_data' => 'rpt_noop'];
        if ($current < $total - 1) {
            $buttons[] = ['text' => '下一页 ▶', 'callback_data' => "rpt_page:{$cacheKey}:{$current}:next"];
        }
        return ['inline_keyboard' => [$buttons]];
    }

    /**
     * 发送给所有管理员，超长内容分页并附翻页按钮
     */
    private function sendToAllAdmins(string $message, Carbon $reportDate): void
    {
        $admins = DB::table('v2_user')
            ->where(function ($q) { $q->where('is_admin', 1)->orWhere('is_staff', 1); })
            ->whereNotNull('telegram_id')
            ->where('banned', 0)
            ->pluck('telegram_id');

        $ts  = new TelegramService();
        $pages = $this->paginate($message);

        foreach ($admins as $tid) {
            if (count($pages) === 1) {
                // 单页直接发送
                $ts->sendMessage((int)$tid, $pages[0], 'markdown');
                continue;
            }

            // 多页：存入 Redis，发第一页 + 翻页按钮
            $cacheKey = md5($tid . $reportDate->format('Ymd'));
            Redis::setex('rpt:pages:' . $cacheKey, 48 * 3600, json_encode($pages));

            $ts->sendMessage(
                (int)$tid,
                $pages[0],
                'markdown',
                $this->buildPageKeyboard($cacheKey, 0, count($pages))
            );
        }
    }

    private function countCurrentlyBanned(): int
    {
        try {
            return count(Redis::keys('sub:banned:*'));
        } catch (\Exception) {
            return 0;
        }
    }
}
