<?php

namespace App\Plugins\Telegram\Commands;

use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Plugins\Telegram\Telegram;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * /grant_days — 批量或单个赠送天数（仅系统管理员）
 *
 * 用法：
 *   /grant_days <天数>                 → 赠送所有有效用户 N 天
 *   /grant_days <天数> all             → 同上
 *   /grant_days <天数> <用户邮箱>       → 赠送指定用户 N 天
 *
 * 性能设计（5000用户场景）：
 *   - 批量更新：1条 SQL UPDATE 完成，不逐行操作
 *   - 通知推送：SendTelegramJob 队列异步发送，命令秒级返回
 *   - 内存安全：chunk(500) 分批读取，不一次性 ->get() 全量加载
 */
class GrantDays extends Telegram
{
    public $command = '/grant_days';
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

        if (empty($args[0]) || !is_numeric($args[0]) || (int)$args[0] <= 0) {
            $this->telegramService->sendMessage(
                $chatId,
                "📖 <b>用法：</b>\n\n" .
                "/grant_days [天数]\n" .
                "/grant_days [天数] all\n" .
                "/grant_days [天数] all [留言]\n" .
                "/grant_days [天数] [用户邮箱]\n" .
                "/grant_days [天数] [用户邮箱] [留言]\n\n" .
                "示例：\n" .
                "• /grant_days 7 — 所有有效用户 +7天\n" .
                "• /grant_days 3 user@example.com — 指定用户 +3天\n" .
                "• /grant_days 7 all 感恩节活动 — 赠送并附言\n" .
                "• /grant_days 3 user@example.com 感谢长期支持 — 指定用户并附言\n\n" .
                "⚠️ 天数从当前有效期顺延叠加，过期用户从当前时间起算",
                'html'
            );
            return;
        }

        $days    = (int) $args[0];
        $target  = isset($args[1]) ? trim($args[1]) : 'all';
        $seconds = $days * 86400;

        // 原因：args[2] 及之后的所有内容拼接
        $reason = '';
        if (isset($args[2])) {
            $reason = implode(' ', array_slice($args, 2));
        }

        // ——— 单个用户 ———
        if ($target !== 'all') {
            $this->handleSingleUser($chatId, $fromId, $target, $days, $seconds, $reason);
            return;
        }

        // ——— 批量：所有有效用户 ———
        $this->handleBatch($chatId, $fromId, $days, $seconds, $reason);
    }

    // ──────────────────────────────────────────────
    // 单用户处理
    // ──────────────────────────────────────────────

    private function handleSingleUser(int $chatId, int $fromId, string $email, int $days, int $seconds, string $reason = ''): void
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->telegramService->sendMessage($chatId, "❌ 未找到用户：<code>" . htmlspecialchars($email) . "</code>", 'html');
            return;
        }

        $oldExpiry = $user->expired_at;
        $newExpiry = $this->calcNewExpiry($oldExpiry, $seconds);

        DB::table('v2_user')->where('id', $user->id)->update(['expired_at' => $newExpiry]);

        $oldStr = $oldExpiry ? date('Y-m-d', $oldExpiry) : '永久';
        $newStr = date('Y-m-d', $newExpiry);

        $reasonLineAdmin = $reason ? "\n💬 备注：" . htmlspecialchars($reason) : '';
        $this->telegramService->sendMessage(
            $chatId,
            "✅ 已赠送 <b>{$days}</b> 天\n\n" .
            "👤 用户：<code>" . htmlspecialchars($email) . "</code>\n" .
            "📅 原到期：{$oldStr}\n" .
            "📅 新到期：{$newStr}" .
            $reasonLineAdmin,
            'html'
        );

        if ($user->telegram_id) {
            $reasonLineUser = $reason ? "\n💬 留言：{$reason}" : '';
            $notifyText = "🎁 管理员赠送了 *{$days}* 天有效期\n\n" .
                          "📅 原到期：*{$oldStr}*  →  *{$newStr}*" .
                          $reasonLineUser . "\n\n" .
                          "感谢您的支持！";
            SendTelegramJob::dispatch((int)$user->telegram_id, $notifyText);
        }

        Log::info('GrantDays: 单用户赠送', [
            'operator'   => $fromId,
            'target'     => $email,
            'days'       => $days,
            'old_expiry' => $oldStr,
            'new_expiry' => $newStr,
            'reason'     => $reason,
        ]);
    }

    // ──────────────────────────────────────────────
    // 批量处理（5000用户优化版）
    // ──────────────────────────────────────────────

    private function handleBatch(int $chatId, int $fromId, int $days, int $seconds, string $reason = ''): void
    {
        $now = time();

        // ① 统计有效用户数（快速 COUNT 查询）
        $total = DB::table('v2_user')
            ->where('banned', 0)
            ->whereNotNull('plan_id')
            ->whereNotNull('expired_at')          // expired_at=null 为永久有效，跳过
            ->where('expired_at', '>', $now)
            ->count();

        if ($total === 0) {
            $this->telegramService->sendMessage($chatId, '⚠️ 当前没有有效用户（永久有效用户不计入）');
            return;
        }

        $this->telegramService->sendMessage(
            $chatId,
            "⏳ 正在处理 <b>{$total}</b> 个用户，请稍候...",
            'html'
        );

        // ② 一条 SQL 批量更新（毫秒级，无论多少用户）
        $affected = DB::table('v2_user')
            ->where('banned', 0)
            ->whereNotNull('plan_id')
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', $now)
            ->update([
                'expired_at' => DB::raw("expired_at + {$seconds}"),
                'updated_at' => time(),
            ]);

        // ③ 立即回复管理员（更新已完成）
        $reasonLineAdmin = $reason ? "\n💬 备注：" . htmlspecialchars($reason) : '';
        $this->telegramService->sendMessage(
            $chatId,
            "✅ 数据库已更新完成\n\n" .
            "🎁 赠送天数：<b>{$days}</b> 天\n" .
            "✔️ 影响用户：<b>{$affected}</b> 人" .
            $reasonLineAdmin . "\n\n" .
            "📨 正在将通知加入发送队列（后台异步）...",
            'html'
        );

        // ④ chunk(500) 分批读取，队列推送通知（不阻塞）
        $queued = 0;
        DB::table('v2_user')
            ->where('banned', 0)
            ->whereNotNull('plan_id')
            ->whereNotNull('telegram_id')
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', $now)      // 取更新后的值
            ->select(['telegram_id', 'expired_at'])
            ->orderBy('id')
            ->chunk(500, function ($users) use ($days, $seconds, $reason, &$queued) {
                foreach ($users as $user) {
                    $newStr     = date('Y-m-d', $user->expired_at);
                    $oldStr     = date('Y-m-d', $user->expired_at - $seconds);
                    $reasonLine = $reason ? "\n💬 留言：{$reason}" : '';
                    $text       = "🎁 管理员赠送了 *{$days}* 天有效期\n\n" .
                                  "📅 原到期：*{$oldStr}*  →  *{$newStr}*" .
                                  $reasonLine . "\n\n" .
                                  "感谢您的支持！";
                    SendTelegramJob::dispatch((int)$user->telegram_id, $text);
                    $queued++;
                }
            });

        // ⑤ 最终汇总
        $this->telegramService->sendMessage(
            $chatId,
            "📨 通知队列加入完成：共 <b>{$queued}</b> 条\n" .
            "（正在由队列 worker 异步发送，无需等待）",
            'html'
        );

        Log::info('GrantDays: 批量赠送完成', [
            'operator' => $fromId,
            'days'     => $days,
            'affected' => $affected,
            'queued'   => $queued,
            'reason'   => $reason,
        ]);
    }

    // ──────────────────────────────────────────────
    // 工具方法
    // ──────────────────────────────────────────────

    /**
     * 计算新到期时间：已过期则从当前时间起算
     */
    private function calcNewExpiry(?int $oldExpiry, int $seconds): int
    {
        $base = ($oldExpiry && $oldExpiry > time()) ? $oldExpiry : time();
        return $base + $seconds;
    }

    /**
     * 验证是否为系统管理员（v2_user.is_admin = 1）
     */
    private function isSystemAdmin(int $telegramId): bool
    {
        try {
            return User::where('telegram_id', $telegramId)
                ->where('is_admin', 1)
                ->exists();
        } catch (\Exception $e) {
            Log::error('GrantDays: 检查管理员权限失败', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
