<?php

namespace App\Plugins\Telegram\Commands;

use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Plugins\Telegram\Telegram;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * /grant_traffic — 批量或单个赠送流量（仅系统管理员）
 *
 * 用法：
 *   /grant_traffic <GB数>               → 赠送所有有效用户 N GB
 *   /grant_traffic <GB数> all           → 同上
 *   /grant_traffic <GB数> <用户邮箱>     → 赠送指定用户 N GB
 *
 * 注：赠送的是 transfer_enable（总配额），不重置已用流量
 *
 * 性能设计（5000用户场景）：
 *   - 批量更新：1条 SQL UPDATE 完成，不逐行操作
 *   - 通知推送：SendTelegramJob 队列异步发送，命令秒级返回
 *   - 内存安全：chunk(500) 分批读取，不一次性 ->get() 全量加载
 */
class GrantTraffic extends Telegram
{
    public $command = '/grant_traffic';
    public $isAdmin = true;

    /** 1 GB in bytes */
    const GB = 1073741824;

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

        if (empty($args[0]) || !is_numeric($args[0]) || (float)$args[0] <= 0) {
            $this->telegramService->sendMessage(
                $chatId,
                "📖 <b>用法：</b>\n\n" .
                "/grant_traffic [GB数]\n" .
                "/grant_traffic [GB数] all\n" .
                "/grant_traffic [GB数] all [留言]\n" .
                "/grant_traffic [GB数] [用户邮箱]\n" .
                "/grant_traffic [GB数] [用户邮箱] [留言]\n\n" .
                "示例：\n" .
                "• /grant_traffic 10 — 所有有效用户 +10 GB\n" .
                "• /grant_traffic 5.5 user@example.com — 指定用户 +5.5 GB\n" .
                "• /grant_traffic 10 all 双十一活动 — 赠送并附言\n" .
                "• /grant_traffic 5 user@example.com 感谢长期支持 — 指定用户并附言\n\n" .
                "⚠️ 赠送的是额外配额，不会重置已使用流量",
                'html'
            );
            return;
        }

        $gb    = (float) $args[0];
        $bytes = (int) round($gb * self::GB);
        $gbStr = $this->formatGb($gb);
        $target = isset($args[1]) ? trim($args[1]) : 'all';

        // 原因：args[2] 及之后的所有内容拼接
        $reason = '';
        if (isset($args[2])) {
            $reason = implode(' ', array_slice($args, 2));
        }

        // ——— 单个用户 ———
        if ($target !== 'all') {
            $this->handleSingleUser($chatId, $fromId, $target, $gb, $bytes, $gbStr, $reason);
            return;
        }

        // ——— 批量：所有有效用户 ———
        $this->handleBatch($chatId, $fromId, $gb, $bytes, $gbStr, $reason);
    }

    // ──────────────────────────────────────────────
    // 单用户处理
    // ──────────────────────────────────────────────

    private function handleSingleUser(
        int $chatId, int $fromId, string $email,
        float $gb, int $bytes, string $gbStr, string $reason = ''
    ): void {
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->telegramService->sendMessage($chatId, "❌ 未找到用户：<code>" . htmlspecialchars($email) . "</code>", 'html');
            return;
        }

        $oldLimit = (int) $user->transfer_enable;
        $newLimit = $oldLimit + $bytes;

        DB::table('v2_user')->where('id', $user->id)->update(['transfer_enable' => $newLimit]);

        $oldStr  = $this->formatBytes($oldLimit);
        $newStr  = $this->formatBytes($newLimit);
        $usedStr = $this->formatBytes((int)$user->u + (int)$user->d);

        $reasonLineAdmin = $reason ? "\n💬 备注：" . htmlspecialchars($reason) : '';
        $this->telegramService->sendMessage(
            $chatId,
            "✅ 已赠送 <b>{$gbStr}</b> 流量\n\n" .
            "👤 用户：<code>" . htmlspecialchars($email) . "</code>\n" .
            "📦 原配额：{$oldStr}\n" .
            "📦 新配额：{$newStr}\n" .
            "📊 已使用：{$usedStr}" .
            $reasonLineAdmin,
            'html'
        );

        if ($user->telegram_id) {
            $reasonLineUser = $reason ? "\n💬 留言：{$reason}" : '';
            $notifyText = "🎁 管理员赠送了本周期额外流量 *{$gbStr}*\n\n" .
                          "📦 配额：*{$oldStr}*  →  *{$newStr}*" .
                          $reasonLineUser . "\n" .
                          "⚠️ 此为本流量周期的一次性补贴，下次流量重置后将恢复套餐标准配额。\n\n" .
                          "感谢您的支持！";
            SendTelegramJob::dispatch((int)$user->telegram_id, $notifyText);
        }

        Log::info('GrantTraffic: 单用户赠送', [
            'operator'  => $fromId,
            'target'    => $email,
            'gb'        => $gb,
            'old_limit' => $oldLimit,
            'new_limit' => $newLimit,
            'reason'    => $reason,
        ]);
    }

    // ──────────────────────────────────────────────
    // 批量处理（5000用户优化版）
    // ──────────────────────────────────────────────

    private function handleBatch(
        int $chatId, int $fromId,
        float $gb, int $bytes, string $gbStr, string $reason = ''
    ): void {
        $now = time();

        // ① 统计有效用户数（快速 COUNT 查询）
        $total = DB::table('v2_user')
            ->where('banned', 0)
            ->whereNotNull('plan_id')
            ->where(function ($q) use ($now) {
                $q->whereNull('expired_at')
                  ->orWhere('expired_at', '>', $now);
            })
            ->count();

        if ($total === 0) {
            $this->telegramService->sendMessage($chatId, '⚠️ 当前没有有效用户');
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
            ->where(function ($q) use ($now) {
                $q->whereNull('expired_at')
                  ->orWhere('expired_at', '>', $now);
            })
            ->update([
                'transfer_enable' => DB::raw("transfer_enable + {$bytes}"),
                'updated_at'      => time(),
            ]);

        // ③ 立即回复管理员（更新已完成）
        $reasonLineAdmin = $reason ? "\n💬 备注：" . htmlspecialchars($reason) : '';
        $this->telegramService->sendMessage(
            $chatId,
            "✅ 数据库已更新完成\n\n" .
            "🎁 赠送流量：<b>{$gbStr}</b>\n" .
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
            ->where(function ($q) use ($now) {
                $q->whereNull('expired_at')
                  ->orWhere('expired_at', '>', $now);
            })
            ->select(['telegram_id', 'transfer_enable'])
            ->orderBy('id')
            ->chunk(500, function ($users) use ($gbStr, $bytes, $reason, &$queued) {
                foreach ($users as $user) {
                    $newStr     = $this->formatBytes((int)$user->transfer_enable);
                    $oldStr     = $this->formatBytes((int)$user->transfer_enable - $bytes);
                    $reasonLine = $reason ? "\n💬 留言：{$reason}" : '';
                    $text       = "🎁 管理员赠送了本周期额外流量 *{$gbStr}*\n\n" .
                                  "📦 配额：*{$oldStr}*  →  *{$newStr}*" .
                                  $reasonLine . "\n" .
                                  "⚠️ 此为本流量周期的一次性补贴，下次流量重置后将恢复套餐标准配额。\n\n" .
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

        Log::info('GrantTraffic: 批量赠送完成', [
            'operator' => $fromId,
            'gb'       => $gb,
            'affected' => $affected,
            'queued'   => $queued,
            'reason'   => $reason,
        ]);
    }

    // ──────────────────────────────────────────────
    // 工具方法
    // ──────────────────────────────────────────────

    private function formatGb(float $gb): string
    {
        return (floor($gb) == $gb ? (int)$gb : $gb) . ' GB';
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
            return User::where('telegram_id', $telegramId)
                ->where('is_admin', 1)
                ->exists();
        } catch (\Exception $e) {
            Log::error('GrantTraffic: 检查管理员权限失败', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
