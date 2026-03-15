<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 套餐到期前 Telegram 预警通知
 *
 * 通知节点：
 *  - 到期前 3 天（第一次预警）
 *  - 到期前 1 天（第二次预警）
 *
 * 每个节点使用 Cache 去重，每 25 小时内不重复发送同一类通知。
 * 踢出通知由 KickExpiredFromGroup 命令在实际踢出时发送。
 */
class NotifyExpiringUsers extends Command
{
    protected $signature = 'telegram:notify-expiring
                            {--dry-run : 演习模式，只检测不发送}';

    protected $description = '向套餐即将到期的用户发送 Telegram 预警通知（到期前3天、1天各一次）';

    /** 通知窗口配置：[提示天数, Cache去重TTL(秒), 缓存key后缀] */
    const NOTICE_WINDOWS = [
        ['days' => 3, 'label' => '3天', 'ttl' => 90000],  // 25h TTL
        ['days' => 1, 'label' => '1天', 'ttl' => 90000],
    ];

    /** 窗口宽度：±12小时，即查找 now+(days-0.5)*86400 ~ now+(days+0.5)*86400 范围内到期的用户 */
    const WINDOW_HALF_SECONDS = 43200; // 12 * 3600

    /** @var TelegramService */
    protected $telegramService;

    public function __construct()
    {
        parent::__construct();
        $this->telegramService = new TelegramService();
    }

    public function handle()
    {
        if (!config('v2board.telegram_bot_enable', 0)) {
            $this->warn('Telegram Bot 未启用，跳过执行');
            return;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('[演习模式] 只检测，不发送实际消息');
        }

        $now = time();
        $totalSent = 0;

        foreach (self::NOTICE_WINDOWS as $window) {
            $days   = $window['days'];
            $label  = $window['label'];
            $ttl    = $window['ttl'];

            $center    = $now + $days * 86400;
            $rangeFrom = $center - self::WINDOW_HALF_SECONDS;
            $rangeTo   = $center + self::WINDOW_HALF_SECONDS;

            $this->line('');
            $this->info("== 查找 {$label} 后到期的用户 (窗口: " . date('m-d H:i', $rangeFrom) . ' ~ ' . date('m-d H:i', $rangeTo) . ') ==');

            $users = User::whereNotNull('telegram_id')
                ->whereNotNull('expired_at')
                ->where('expired_at', '>=', $rangeFrom)
                ->where('expired_at', '<=', $rangeTo)
                ->where('banned', 0)
                ->get();

            if ($users->isEmpty()) {
                $this->line("  无符合条件的用户");
                continue;
            }

            $this->line("  找到 {$users->count()} 个用户");

            foreach ($users as $user) {
                $cacheKey = "tg_expire_notify_{$user->id}_{$days}d";

                // 去重：25小时内已发过，跳过
                if (Cache::has($cacheKey)) {
                    $this->line("  ⬛ {$user->email} ({$label}通知) 已发送过，跳过");
                    continue;
                }

                $expiredAt = date('Y-m-d H:i', $user->expired_at);
                $this->line("  🔔 {$user->email} (TG:{$user->telegram_id}) 到期:{$expiredAt}");

                if ($dryRun) {
                    $this->info("     [演习] 将发送{$label}预警（不执行）");
                    continue;
                }

                $sent = $this->sendWarningNotice($user, $days, $label);

                if ($sent) {
                    Cache::put($cacheKey, 1, $ttl);
                    $this->info("     ✅ 发送成功");
                    $totalSent++;
                } else {
                    $this->warn("     ❌ 发送失败");
                }

                usleep(300000); // 300ms，避免频率限制
            }
        }

        $this->line('');
        $this->info("本次共发送通知: {$totalSent} 条");

        Log::info('NotifyExpiring: 执行完成', [
            'total_sent' => $totalSent,
            'dry_run'    => $dryRun,
        ]);
    }

    /**
     * 发送到期预警私信
     */
    private function sendWarningNotice(User $user, int $days, string $label): bool
    {
        try {
            $expiredAt = date('Y-m-d', $user->expired_at);

            if ($days === 1) {
                $urgency = "⚠️ *最后提醒* — 您的套餐将于 *明天* ({$expiredAt}) 到期";
                $tip     = "‼️ 到期后将自动移出群组，请尽快续费！";
            } else {
                $urgency = "🔔 *到期提醒* — 您的套餐将于 *{$label}后* ({$expiredAt}) 到期";
                $tip     = "请提前续费，避免服务中断和被移出群组。";
            }

            $message = "{$urgency}\n\n" .
                       "{$tip}\n\n" .
                       "📌 *续费后如何重新入群：*\n" .
                       "续费完成后，私聊机器人发送 /join 即可获取邀请链接。\n\n" .
                       "如有问题请联系客服。";

            $result = $this->telegramService->sendMessage(
                (int) $user->telegram_id,
                $message,
                'markdown'
            );

            $ok = $result && isset($result->ok) && $result->ok;

            Log::info('NotifyExpiring: 发送到期预警', [
                'email'      => $user->email,
                'telegram_id'=> $user->telegram_id,
                'days_left'  => $days,
                'expired_at' => $expiredAt,
                'success'    => $ok,
            ]);

            return $ok;

        } catch (\Exception $e) {
            Log::error('NotifyExpiring: 发送异常', [
                'email'      => $user->email,
                'telegram_id'=> $user->telegram_id,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }
}
