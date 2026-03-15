<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\WatchNotifyService;
use Illuminate\Support\Facades\Log;

/**
 * /watch — 管理监控名单（仅系统管理员）
 *
 * 用法：
 *   /watch add <邮箱> [备注]  → 将用户加入监控名单
 *   /watch remove <邮箱>      → 移出监控名单
 *   /watch list               → 查看当前监控名单
 *
 * 数据存储在 storage/app/watch_list.json，无需数据库。
 *
 * 触发通知场景：
 *   - 被监控用户拉取订阅
 *   - 被监控用户购买 / 续费订阅
 *   - 与被监控用户曾用 IP 相同的其他账号进行上述操作
 */
class Watch extends Telegram
{
    public $command = '/watch';
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
        $sub  = strtolower(trim($args[0] ?? ''));

        match ($sub) {
            'add'          => $this->handleAdd($chatId, $fromId, $args),
            'remove', 'rm' => $this->handleRemove($chatId, $args),
            'list'         => $this->handleList($chatId),
            'scan'         => $this->handleScan($chatId),
            default        => $this->sendUsage($chatId),
        };
    }

    // ──────────────────────────────────────────────
    // 添加监控
    // ──────────────────────────────────────────────

    private function handleAdd(int $chatId, int $fromId, array $args): void
    {
        $email = trim($args[1] ?? '');
        if (!$email) {
            $this->telegramService->sendMessage($chatId, '❌ 请提供用户邮箱');
            return;
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->telegramService->sendMessage(
                $chatId,
                '❌ 未找到用户：<code>' . htmlspecialchars($email) . '</code>',
                'html'
            );
            return;
        }

        if (WatchNotifyService::isWatched($user->id)) {
            $this->telegramService->sendMessage($chatId, '⚠️ 该用户已在监控名单中');
            return;
        }

        $note = isset($args[2]) ? implode(' ', array_slice($args, 2)) : '';
        WatchNotifyService::addUser($user->id, $email, $note, $fromId);

        $noteStr = $note ? "\n📝 备注：" . htmlspecialchars($note) : '';
        $this->telegramService->sendMessage(
            $chatId,
            "✅ 已加入监控名单\n\n" .
            "👤 <code>" . htmlspecialchars($email) . "</code>" . $noteStr . "\n\n" .
            "以下操作将实时通知所有管理员：\n" .
            "• 拉取订阅\n" .
            "• 购买 / 续费订阅\n" .
            "• 曾用同 IP 的其他账号进行上述操作",
            'html'
        );

        Log::info('Watch: 添加监控', ['operator' => $fromId, 'target' => $email, 'user_id' => $user->id]);
    }

    // ──────────────────────────────────────────────
    // 移除监控
    // ──────────────────────────────────────────────

    private function handleRemove(int $chatId, array $args): void
    {
        $email = trim($args[1] ?? '');
        if (!$email) {
            $this->telegramService->sendMessage($chatId, '❌ 请提供用户邮箱');
            return;
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->telegramService->sendMessage(
                $chatId,
                '❌ 未找到用户：<code>' . htmlspecialchars($email) . '</code>',
                'html'
            );
            return;
        }

        if (!WatchNotifyService::removeUser($user->id)) {
            $this->telegramService->sendMessage($chatId, '⚠️ 该用户不在监控名单中');
            return;
        }

        $this->telegramService->sendMessage(
            $chatId,
            "✅ 已移出监控名单\n\n👤 <code>" . htmlspecialchars($email) . "</code>",
            'html'
        );

        Log::info('Watch: 移除监控', ['target' => $email, 'user_id' => $user->id]);
    }

    // ──────────────────────────────────────────────
    // 查看监控名单
    // ──────────────────────────────────────────────

    private function handleList(int $chatId): void
    {
        $list = WatchNotifyService::getList();

        if (empty($list)) {
            $this->telegramService->sendMessage($chatId, '📋 监控名单为空');
            return;
        }

        $userIds    = array_map('intval', array_keys($list));
        $ipsPerUser = WatchNotifyService::getWatchedUsersIps($userIds);

        // 批量查订阅信息
        $users = User::whereIn('id', $userIds)
            ->select('id', 'plan_id', 'transfer_enable', 'u', 'd', 'expired_at')
            ->get()
            ->keyBy('id');
        $planIds = $users->pluck('plan_id')->filter()->unique()->toArray();
        $plans   = \App\Models\Plan::whereIn('id', $planIds)
            ->select('id', 'name')
            ->get()
            ->keyBy('id');

        $count  = count($list);
        $blocks = [];
        foreach ($list as $userId => $entry) {
            $block = "👤 <code>" . htmlspecialchars($entry['email']) . "</code>\n";
            if (!empty($entry['note'])) {
                $block .= "   📝 " . htmlspecialchars($entry['note']) . "\n";
            }
            $block .= "   🕐 加入：" . date('Y-m-d', $entry['created_at']) . "\n";

            // 订阅信息
            $u = $users[(int)$userId] ?? null;
            if ($u) {
                $planName = isset($plans[$u->plan_id]) ? htmlspecialchars($plans[$u->plan_id]->name) : '无套餐';
                $used     = round(($u->u + $u->d) / 1073741824, 2);
                $total    = round($u->transfer_enable / 1073741824, 2);
                $expiry   = $u->expired_at ? date('Y-m-d', $u->expired_at) : '永久';
                $block .= "   📦 套餐：{$planName}  到期：{$expiry}\n";
                $block .= "   📊 流量：{$used} GB / {$total} GB\n";
            }

            // 历史订阅拉取 IP
            $ips = $ipsPerUser[(int)$userId] ?? [];
            if (!empty($ips)) {
                $block .= "   🌐 历史 IP：\n";
                foreach ($ips as $ipInfo) {
                    $loc    = trim(($ipInfo['country'] ?? '') . ' ' . ($ipInfo['city'] ?? ''));
                    $locStr = $loc ? "  ({$loc})" : '';
                    $date   = date('Y-m-d', strtotime($ipInfo['last_seen']));
                    $block .= "      • <code>{$ipInfo['ip']}</code>{$locStr}  {$date}\n";
                }
            }

            $blocks[] = $block;
        }

        $header  = "📋 <b>监控名单（共 {$count} 人）</b>\n━━━━━━━━━━━━━━━━\n\n";
        $current = $header;
        foreach ($blocks as $block) {
            if (mb_strlen($current . $block) > 3800) {
                $this->telegramService->sendMessage($chatId, $current, 'html');
                $current = $block . "\n";
            } else {
                $current .= $block . "\n";
            }
        }
        if ($current !== $header && $current !== '') {
            $this->telegramService->sendMessage($chatId, $current, 'html');
        }
    }

    // ──────────────────────────────────────────────
    // IP 关联扫描
    // ──────────────────────────────────────────────

    private function handleScan(int $chatId): void
    {
        $this->telegramService->sendMessage($chatId, '🔍 正在扫描全部历史记录，请稍候…', 'html');

        $results = WatchNotifyService::scanSharedIps();

        if (empty($results)) {
            $this->telegramService->sendMessage($chatId, '✅ 未发现监控用户与其他账号共享 IP 的情况');
            return;
        }

        // Telegram 单条消息上限 4096 字符，超出时分段发送
        $blocks = [];
        foreach ($results as $watchedUserId => $info) {
            $block  = "👤 <b>" . htmlspecialchars($info['email']) . "</b>";
            if (!empty($info['note'])) {
                $block .= "  <i>(" . htmlspecialchars($info['note']) . ")</i>";
            }
            $block .= "\n";

            foreach ($info['ip_groups'] as $ip => $users) {
                $block .= "  🌐 <code>{$ip}</code>  共 " . count($users) . " 个关联账号\n";
                foreach ($users as $u) {
                    $block .= "    └ <code>" . htmlspecialchars($u['email']) . "</code>\n";
                }
            }
            $blocks[] = $block;
        }

        $header  = "🔍 <b>IP 关联扫描结果（共 " . count($results) . " 个监控用户有关联）</b>\n━━━━━━━━━━━━━━━━\n\n";
        $current = $header;
        foreach ($blocks as $block) {
            if (mb_strlen($current . $block) > 3800) {
                $this->telegramService->sendMessage($chatId, $current, 'html');
                $current = $block . "\n";
            } else {
                $current .= $block . "\n";
            }
        }
        if ($current !== $header && $current !== '') {
            $this->telegramService->sendMessage($chatId, $current, 'html');
        }
    }

    // ──────────────────────────────────────────────
    // 用法说明
    // ──────────────────────────────────────────────

    private function sendUsage(int $chatId): void
    {
        $this->telegramService->sendMessage(
            $chatId,
            "📖 <b>用法：</b>\n\n" .
            "/watch add [邮箱] — 加入监控名单\n" .
            "/watch add [邮箱] [备注] — 加入并附注说明\n" .
            "/watch remove [邮箱] — 移出监控名单\n" .
            "/watch list — 查看当前监控名单\n" .
            "/watch scan — 扫描监控用户的 IP 关联账号\n\n" .
            "<b>触发通知的操作：</b>\n" .
            "• 拉取订阅\n" .
            "• 购买 / 续费订阅\n" .
            "• 曾用同 IP 的其他账号进行上述操作",
            'html'
        );
    }

    // ──────────────────────────────────────────────
    // 工具方法
    // ──────────────────────────────────────────────

    private function isSystemAdmin(int $telegramId): bool
    {
        try {
            return User::where('telegram_id', $telegramId)
                ->where('is_admin', 1)
                ->exists();
        } catch (\Exception $e) {
            Log::error('Watch: 检查管理员权限失败', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
