<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * /subunban — 管理员手动解除用户订阅封禁
 *
 * 用法（私聊）：
 *   /subunban <邮箱|用户ID>
 *
 * 流程：
 *   1. 展示封禁详情 + 最近24h拉取记录
 *   2. 管理员点击「确认解封」或「取消」按钮
 */
class SubUnban extends Telegram
{
    public $command = '/subunban';
    public $isAdmin = true;

    public function handle($message, $match = [])
    {
        $chatId    = $message->chat_id;
        $fromId    = $message->from_id ?? null;
        $isPrivate = $message->is_private ?? false;

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

        $args = $message->args ?? [];

        if (empty($args[0])) {
            $this->telegramService->sendMessage(
                $chatId,
                "📖 <b>用法：</b>\n\n"
                . "<code>/subunban [邮箱]</code>    — 按邮箱查询\n"
                . "<code>/subunban [用户ID]</code>  — 按网站用户ID查询\n\n"
                . "示例：\n"
                . "<code>/subunban user@example.com</code>\n"
                . "<code>/subunban 1733</code>",
                'html'
            );
            return;
        }

        $input = trim($args[0]);
        $user  = is_numeric($input)
            ? User::find((int)$input)
            : User::where('email', $input)->first();

        if (!$user) {
            $this->telegramService->sendMessage($chatId, "❌ 未找到用户：" . htmlspecialchars($input), 'html');
            return;
        }

        $banKey = 'sub:banned:' . $user->id;
        $banned = (bool)Redis::exists($banKey);

        if (!$banned) {
            $this->telegramService->sendMessage(
                $chatId,
                "ℹ️ 用户 <code>{$user->email}</code> 当前没有订阅封禁",
                'html'
            );
            return;
        }

        // 封禁原因
        $banType   = Redis::get($banKey);
        $banTtl    = (int)Redis::ttl($banKey);
        $banReason = $banType === 'rate' ? '每分钟拉取频率超限' : '24小时内不同IP超限';
        $banRemain = $banTtl > 0 ? ceil($banTtl / 3600) . ' 小时后自动解除' : '即将解除';

        // 最近24h拉取详情
        $logs = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['ip', 'os', 'device', 'country', 'city', 'created_at']);

        $pull24h  = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        $ipCount = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->distinct('ip')
            ->count('ip');

        // 解封次数
        $unbanCount = DB::table('v2_subscribe_unban_log')
            ->where('user_id', $user->id)
            ->count();

        $text  = "🔍 <b>订阅封禁详情</b>\n";
        $text .= "━━━━━━━━━━━━━━━\n";
        $text .= "👤 用户：<code>{$user->email}</code>\n";
        $text .= "🆔 用户ID：{$user->id}\n";
        $text .= "🚫 封禁原因：{$banReason}\n";
        $text .= "⏳ 剩余时长：{$banRemain}\n";
        $text .= "🔓 历史解封次数：{$unbanCount} 次\n";
        $text .= "━━━━━━━━━━━━━━━\n";
        $text .= "📊 <b>24小时拉取统计</b>\n";
        $text .= "• 拉取总次数：{$pull24h} 次\n";
        $text .= "• 不同IP数：{$ipCount} 个\n";

        if ($logs->isNotEmpty()) {
            $text .= "\n🕐 <b>最近10条记录：</b>\n";
            foreach ($logs as $log) {
                $time     = date('H:i:s', strtotime($log->created_at));
                $location = trim(($log->city ?? '') . ' ' . ($log->country ?? ''));
                $text .= "• {$time}｜{$log->ip}｜{$log->os}/{$log->device}";
                if ($location) $text .= "｜{$location}";
                $text .= "\n";
            }
        }

        $buttons = [
            'inline_keyboard' => [[
                ['text' => '✅ 确认解封', 'callback_data' => "sub_unban:confirm:{$user->id}"],
                ['text' => '❌ 取消',     'callback_data' => "sub_unban:cancel:{$user->id}"],
            ]]
        ];

        $this->telegramService->sendMessage($chatId, $text, 'html', $buttons);
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
