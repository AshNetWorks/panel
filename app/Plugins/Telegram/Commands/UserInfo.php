<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\Plan;
use App\Models\User;
use App\Plugins\Telegram\Telegram;

/**
 * /userinfo — 查询指定用户详情（仅系统管理员，私聊）
 *
 * 用法：
 *   /userinfo <用户邮箱>
 */
class UserInfo extends Telegram
{
    public $command = '/userinfo';
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

        $args = $message->args ?? [];

        if (empty($args[0])) {
            $this->telegramService->sendMessage(
                $chatId,
                "📖 <b>用法：</b>\n\n"
                . "<code>/userinfo [邮箱]</code>      — 按邮箱查询\n"
                . "<code>/userinfo [网站ID]</code>    — 按网站用户ID查询\n"
                . "<code>/userinfo tg:[TG ID]</code>  — 按 Telegram ID 查询\n\n"
                . "示例：\n"
                . "<code>/userinfo user@example.com</code>\n"
                . "<code>/userinfo 1733</code>\n"
                . "<code>/userinfo tg:6878406068</code>",
                'html'
            );
            return;
        }

        $input = trim($args[0]);
        $user  = null;
        $label = $input; // 用于错误提示

        if (strpos($input, 'tg:') === 0) {
            // 强制按 Telegram ID 查询
            $tgId  = (int) substr($input, 3);
            $user  = User::where('telegram_id', $tgId)->first();
            $label = "TG ID {$tgId}";
        } elseif (is_numeric($input)) {
            // 纯数字：先按网站ID，找不到再按 Telegram ID
            $user = User::find((int) $input);
            if (!$user) {
                $user  = User::where('telegram_id', (int) $input)->first();
                $label = "ID/TG {$input}";
            }
        } else {
            // 否则按邮箱
            $user = User::where('email', $input)->first();
        }

        $email = $user?->email ?? $input; // 后续显示用

        if (!$user) {
            $this->telegramService->sendMessage($chatId, "❌ 未找到用户：" . htmlspecialchars($label), 'html');
            return;
        }

        $now = time();

        // —— 套餐信息 ——
        $planName = '未订阅';
        if ($user->plan_id) {
            $plan     = Plan::find($user->plan_id);
            $planName = $plan ? $plan->name : "套餐#{$user->plan_id}";
        }

        // —— 有效期 ——
        if (!$user->expired_at) {
            $expireStr   = '永久有效';
            $statusEmoji = '♾️';
        } elseif ($user->expired_at > $now) {
            $expireStr   = date('Y-m-d', $user->expired_at);
            $days        = (int) ceil(($user->expired_at - $now) / 86400);
            $statusEmoji = $days <= 3 ? '⚠️' : '✅';
        } else {
            $expireStr   = date('Y-m-d', $user->expired_at);
            $statusEmoji = '❌';
        }

        // —— 流量 ——
        $used  = (int)$user->u + (int)$user->d;
        $total = (int)$user->transfer_enable;

        // —— Telegram ——
        $tgId      = $user->telegram_id;
        $tgStr     = $tgId ? (string)$tgId : '未绑定';
        $inGroupStr = $tgId ? $this->checkInGroup((int)$tgId) : '─';

        // —— 封禁 ——
        $bannedStr = $user->banned ? '🚫 已封禁' : '✅ 正常';

        // —— 余额（存储单位：分，显示转换为元）——
        $balance = number_format(($user->balance ?? 0) / 100, 2);
        $commBal = number_format(($user->commission_balance ?? 0) / 100, 2);

        // —— 时间 ——
        $createdStr   = $user->created_at ? date('Y-m-d H:i', $user->created_at) : '─';
        $lastLoginStr = $user->t          ? date('Y-m-d H:i', $user->t)          : '从未登录';

        // —— 邀请关系 ——
        $inviterStr = '无';
        if ($user->invite_user_id) {
            $inviter    = User::find($user->invite_user_id);
            $inviterStr = $inviter ? htmlspecialchars($inviter->email) : "ID:{$user->invite_user_id}";
        }
        $invitedCount = User::where('invite_user_id', $user->id)->count();

        $text  = "👤 <b>用户信息</b>\n";
        $text .= "━━━━━━━━━━━━━━━\n";
        $text .= "🆔 网站ID：{$user->id}\n";
        $text .= "📧 邮箱：<code>" . htmlspecialchars($email) . "</code>\n";
        $text .= "📱 TG ID：{$tgStr}\n";
        $text .= "📦 套餐：{$planName}\n";
        $text .= "💰 余额：{$balance} 元\n";
        $text .= "🏷 佣金余额：{$commBal} 元\n";
        $text .= "📊 流量：" . $this->formatBytes($used) . " / " . $this->formatBytes($total) . "\n";
        $text .= "⏳ 到期：{$statusEmoji} {$expireStr}\n";
        $text .= "🔒 状态：{$bannedStr}\n";
        $text .= "👥 在群组：{$inGroupStr}\n";
        $text .= "📅 注册：{$createdStr}\n";
        $text .= "🕐 登录：{$lastLoginStr}\n";
        $text .= "━━━━━━━━━━━━━━━\n";
        $text .= "👥 邀请人：{$inviterStr}\n";
        $text .= "📨 已邀请：{$invitedCount} 人\n";

        $this->telegramService->sendMessage($chatId, $text, 'html');
    }

    /**
     * 检查用户是否在群组中
     */
    private function checkInGroup(int $telegramId): string
    {
        try {
            $member = $this->telegramService->getChatMember(config('v2board.telegram_group_id'), $telegramId);
            if (!$member || !isset($member->status)) {
                return '不在群组';
            }
            $map = [
                'member'        => '✅ 在群组',
                'administrator' => '✅ 管理员',
                'creator'       => '✅ 群主',
                'restricted'    => '⚠️ 已限制',
                'left'          => '离开群组',
                'kicked'        => '🚫 已踢出',
            ];
            return $map[$member->status] ?? $member->status;
        } catch (\Exception $e) {
            return '查询失败';
        }
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, $precision) . ' ' . $units[$i];
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
