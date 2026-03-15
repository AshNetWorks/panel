<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * /set_rate — 按关键词批量调整服务器倍率（仅系统管理员）
 *
 * 用法：
 *   /set_rate <倍率> <关键词>
 *
 * 示例：
 *   /set_rate 1.5 香港   → 将名称含「香港」的所有节点倍率设为 1.5
 *   /set_rate 2 日本      → 将名称含「日本」的所有节点倍率设为 2
 *   /set_rate 0.5 IPLC   → 将名称含「IPLC」的所有节点倍率设为 0.5
 */
class SetRate extends Telegram
{
    public $command = '/set_rate';
    public $isAdmin = true;

    /** 所有包含 rate 字段的服务器表 → 显示类型名 */
    const SERVER_TABLES = [
        'v2_server_vmess'       => 'VMess',
        'v2_server_vless'       => 'VLess',
        'v2_server_trojan'      => 'Trojan',
        'v2_server_shadowsocks' => 'Shadowsocks',
        'v2_server_hysteria'    => 'Hysteria',
        'v2_server_tuic'        => 'TUIC',
        'v2_server_anytls'      => 'AnyTLS',
        'v2_server_v2node'      => 'V2node',
    ];

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

        if (count($args) < 2 || !is_numeric($args[0]) || (float)$args[0] <= 0) {
            $this->telegramService->sendMessage(
                $chatId,
                "📖 <b>用法：</b>\n\n" .
                "/set_rate [倍率] [关键词]\n\n" .
                "示例：\n" .
                "• /set_rate 1.5 香港 — 名称含「香港」的节点 → 倍率 1.5x\n" .
                "• /set_rate 2 日本 — 名称含「日本」的节点 → 倍率 2x\n" .
                "• /set_rate 0.5 IPLC — 名称含「IPLC」的节点 → 倍率 0.5x\n\n" .
                "⚠️ 倍率须为正数，支持小数",
                'html'
            );
            return;
        }

        $newRate = (float) $args[0];
        // 关键词允许含空格，取第2个参数起合并
        $keyword = implode(' ', array_slice($args, 1));

        // 搜索并更新所有表
        $matched      = [];
        $totalUpdated = 0;

        foreach (self::SERVER_TABLES as $table => $typeName) {
            $servers = DB::table($table)
                ->where('name', 'LIKE', "%{$keyword}%")
                ->select(['id', 'name', 'rate'])
                ->get();

            if ($servers->isEmpty()) continue;

            foreach ($servers as $server) {
                $matched[] = [
                    'type'     => $typeName,
                    'name'     => $server->name,
                    'old_rate' => (float) $server->rate,
                ];
            }

            $ids = $servers->pluck('id')->toArray();
            DB::table($table)->whereIn('id', $ids)->update(['rate' => $newRate]);
            $totalUpdated += count($ids);
        }

        if (empty($matched)) {
            $this->telegramService->sendMessage(
                $chatId,
                "⚠️ 未找到名称含「" . htmlspecialchars($keyword) . "」的节点",
                'html'
            );
            return;
        }

        // 构建结果消息
        $newRateStr = $this->formatRate($newRate);
        $kw = htmlspecialchars($keyword);
        $lines = [
            "✅ 已更新 <b>{$totalUpdated}</b> 个节点的倍率",
            "🔍 关键词：<code>{$kw}</code>",
            "📊 新倍率：<b>{$newRateStr}</b>\n",
        ];

        foreach ($matched as $item) {
            $oldStr  = $this->formatRate($item['old_rate']);
            $name    = htmlspecialchars($item['name']);
            $lines[] = "• [{$item['type']}] {$name}: {$oldStr} → <b>{$newRateStr}</b>";
        }

        $text = implode("\n", $lines);

        // 超过 4000 字符时截断
        if (mb_strlen($text) > 4000) {
            $head = array_slice($lines, 0, 4);
            $text = implode("\n", $head) .
                    "\n\n<i>（共 {$totalUpdated} 个节点已更新，列表过长已截断）</i>";
        }

        $this->telegramService->sendMessage($chatId, $text, 'html');

        Log::info('SetRate: 批量调整倍率', [
            'operator' => $fromId,
            'keyword'  => $keyword,
            'new_rate' => $newRate,
            'updated'  => $totalUpdated,
        ]);
    }

    // ──────────────────────────────────────────────
    // 工具方法
    // ──────────────────────────────────────────────

    /** 格式化倍率：整数显示为整数，小数保留必要位数 */
    private function formatRate(float $rate): string
    {
        return (floor($rate) == $rate ? (int)$rate : $rate) . 'x';
    }

    private function isSystemAdmin(int $telegramId): bool
    {
        try {
            return User::where('telegram_id', $telegramId)
                ->where('is_admin', 1)
                ->exists();
        } catch (\Exception $e) {
            Log::error('SetRate: 检查管理员权限失败', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
