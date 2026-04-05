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
                . "<code>/subunban [邮箱]</code>       — 按邮箱查询\n"
                . "<code>/subunban [用户ID]</code>     — 按网站用户ID查询\n"
                . "<code>/subunban tg:[TG UID]</code>  — 按 Telegram UID 查询\n\n"
                . "示例：\n"
                . "<code>/subunban user@example.com</code>\n"
                . "<code>/subunban 1733</code>\n"
                . "<code>/subunban tg:6878406068</code>",
                'html'
            );
            return;
        }

        $input = trim($args[0]);
        $user  = null;

        if (strpos($input, 'tg:') === 0) {
            $tgId = (int)substr($input, 3);
            $user = User::where('telegram_id', $tgId)->first();
        } elseif (is_numeric($input)) {
            $user = User::find((int)$input);
        } else {
            $user = User::where('email', $input)->first();
        }

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

        // 24h拉取详情（只统计真实拉取，过滤封禁事件日志）
        $logs = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->where('blocked', 0)
            ->orderBy('created_at', 'desc')
            ->get(['ip', 'os', 'device', 'country', 'city', 'created_at']);

        $pull24h  = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->where('blocked', 0)
            ->count();

        $ipCount = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->where('blocked', 0)
            ->distinct('ip')
            ->count('ip');

        // 解封次数
        $unbanCount = DB::table('v2_subscribe_unban_log')
            ->where('user_id', $user->id)
            ->count();

        // 第一条：概要 + 操作按钮
        $summary  = "🔍 <b>订阅封禁详情</b>\n";
        $summary .= "━━━━━━━━━━━━━━━\n";
        $summary .= "👤 用户：<code>{$user->email}</code>\n";
        $summary .= "🆔 用户ID：{$user->id}\n";
        $summary .= "🚫 封禁原因：{$banReason}\n";
        $summary .= "⏳ 剩余时长：{$banRemain}\n";
        $summary .= "🔓 历史解封次数：{$unbanCount} 次\n";
        $summary .= "━━━━━━━━━━━━━━━\n";
        $summary .= "📊 <b>24小时拉取统计</b>\n";
        $summary .= "• 拉取总次数：{$pull24h} 次\n";
        $summary .= "• 不同IP数：{$ipCount} 个";

        $buttons = [
            'inline_keyboard' => [[
                ['text' => '✅ 确认解封', 'callback_data' => "sub_unban:confirm:{$user->id}"],
                ['text' => '❌ 取消',     'callback_data' => "sub_unban:cancel:{$user->id}"],
            ]]
        ];

        $this->telegramService->sendMessage($chatId, $summary, 'html', $buttons);

        // 第二条起：拉取日志（按 4000 字符分片发送）
        if ($logs->isNotEmpty()) {
            $lines = [];
            foreach ($logs as $log) {
                $time     = date('H:i:s', strtotime($log->created_at));
                $location = trim(($log->city ?? '') . ' ' . ($log->country ?? ''));
                $line = "• {$time}｜{$log->ip}｜{$log->os}/{$log->device}";
                if ($location) $line .= "｜{$location}";
                $lines[] = $line;
            }

            $total = count($lines);
            $this->sendChunked($chatId, "🕐 <b>24小时拉取记录（共 {$total} 条）：</b>\n" . implode("\n", $lines), 'html');
        }
    }

    private function sendChunked(int $chatId, string $text, string $parseMode = '', int $limit = 4000): void
    {
        if (mb_strlen($text) <= $limit) {
            $this->telegramService->sendMessage($chatId, $text, $parseMode);
            return;
        }

        $lines   = explode("\n", $text);
        $chunk   = '';

        foreach ($lines as $line) {
            $candidate = $chunk === '' ? $line : $chunk . "\n" . $line;
            if (mb_strlen($candidate) > $limit) {
                $this->telegramService->sendMessage($chatId, $chunk, $parseMode);
                $chunk = $line;
            } else {
                $chunk = $candidate;
            }
        }

        if ($chunk !== '') {
            $this->telegramService->sendMessage($chatId, $chunk, $parseMode);
        }
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
