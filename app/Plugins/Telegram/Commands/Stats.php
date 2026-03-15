<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use Illuminate\Support\Facades\DB;

/**
 * /stats — 系统用户概况（仅系统管理员，私聊）
 */
class Stats extends Telegram
{
    public $command = '/stats';
    public $isAdmin = true;

    public function handle($message, $match = [])
    {
        $chatId    = $message->chat_id;
        $fromId    = $message->from_id ?? null;
        $isPrivate = ($message->is_private ?? false) ||
                     (isset($message->chat->type) && $message->chat->type === 'private');

        if (!$isPrivate) {
            $this->telegramService->sendMessageWithAutoDelete(
                $chatId, '❌ 此命令只能在私聊中使用', '', 30, $message->message_id
            );
            return;
        }

        if (!$fromId || !$this->isSystemAdmin($fromId)) {
            $this->telegramService->sendMessage($chatId, '❌ 权限不足，此命令仅系统管理员可用');
            return;
        }

        $now       = time();
        $in24h     = $now + 86400;
        $in3days   = $now + 86400 * 3;

        // —— 统计查询（全部走索引，毫秒级）——
        $totalUsers    = DB::table('v2_user')->count();
        $bannedUsers   = DB::table('v2_user')->where('banned', 1)->count();
        $tgBound       = DB::table('v2_user')->whereNotNull('telegram_id')->count();

        // 有效用户：未封禁 + 有套餐 + (永久 OR 未到期)
        $activeUsers = DB::table('v2_user')
            ->where('banned', 0)
            ->whereNotNull('plan_id')
            ->where(function ($q) use ($now) {
                $q->whereNull('expired_at')->orWhere('expired_at', '>', $now);
            })
            ->count();

        // 永久有效用户
        $permanentUsers = DB::table('v2_user')
            ->where('banned', 0)
            ->whereNotNull('plan_id')
            ->whereNull('expired_at')
            ->count();

        // 今日到期（24h 内）
        $expiringToday = DB::table('v2_user')
            ->where('banned', 0)
            ->where('expired_at', '>', $now)
            ->where('expired_at', '<=', $in24h)
            ->count();

        // 3天内到期
        $expiring3Days = DB::table('v2_user')
            ->where('banned', 0)
            ->where('expired_at', '>', $now)
            ->where('expired_at', '<=', $in3days)
            ->count();

        // 已过期（未封禁，含绑定TG的）
        $expiredTotal = DB::table('v2_user')
            ->where('banned', 0)
            ->whereNotNull('expired_at')
            ->where('expired_at', '<=', $now)
            ->count();

        // 过期且绑定TG（潜在群组残留成员）
        $expiredWithTg = DB::table('v2_user')
            ->where('banned', 0)
            ->whereNotNull('expired_at')
            ->where('expired_at', '<=', $now)
            ->whereNotNull('telegram_id')
            ->count();

        // 有效 + 绑定TG
        $activeTgBound = DB::table('v2_user')
            ->where('banned', 0)
            ->whereNotNull('plan_id')
            ->whereNotNull('telegram_id')
            ->where(function ($q) use ($now) {
                $q->whereNull('expired_at')->orWhere('expired_at', '>', $now);
            })
            ->count();

        $text = "📊 *系统用户概况*\n";
        $text .= "─────────────────\n";
        $text .= "👥 注册总数：*{$totalUsers}*\n";
        $text .= "✅ 有效用户：*{$activeUsers}*\n";
        $text .= "♾️ 永久有效：*{$permanentUsers}*\n";
        $text .= "🚫 封禁用户：*{$bannedUsers}*\n";
        $text .= "❌ 已过期：*{$expiredTotal}*\n";
        $text .= "─────────────────\n";
        $text .= "📅 24h内到期：*{$expiringToday}*\n";
        $text .= "📅 3天内到期：*{$expiring3Days}*\n";
        $text .= "─────────────────\n";
        $text .= "🔗 绑定TG总数：*{$tgBound}*\n";
        $text .= "🔗 有效+绑定TG：*{$activeTgBound}*\n";
        $text .= "⚠️ 过期+绑定TG：*{$expiredWithTg}*（潜在群组残留）\n";
        $text .= "─────────────────\n";
        $text .= "🕐 统计时间：" . date('Y-m-d H:i') . "\n";

        $this->telegramService->sendMessage($chatId, $text, 'markdown');
    }

    private function isSystemAdmin(int $telegramId): bool
    {
        try {
            return User::where('telegram_id', $telegramId)->where('is_admin', 1)->exists();
        } catch (\Exception $e) {
            return false;
        }
    }
}
