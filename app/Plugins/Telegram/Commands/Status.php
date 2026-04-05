<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use Carbon\Carbon;

class Status extends Telegram
{
    public $command = '/status';
    public $description = '查看账户状态';

    public function handle($msg, $match = [])
    {
        $telegramService = $this->telegramService;
        
        $user = User::where('telegram_id', $msg->chat_id)->first();
        if (!$user) {
            $telegramService->sendMessage($msg->chat_id, '❌ 未找到绑定的用户信息');
            return;
        }
        
        $statusText = "👤 *账户状态*\n\n";
        $statusText .= "📧 邮箱: " . $this->escapeMarkdown($user->email) . "\n";
        $expiredDisplay = $user->expired_at ? Carbon::parse($user->expired_at)->format('Y-m-d H:i') : '永久有效';
        $statusText .= "📅 到期时间: " . $expiredDisplay . "\n";
        $statusText .= "🚫 封禁状态: " . ($user->banned ? '❌ 已封禁' : '✅ 正常') . "\n\n";

        $statusText .= "🔔 *通知设置*\n";
        $statusText .= "状态: " . ($user->telegram_daily_traffic_notify ? '🟢 已开启' : '🔴 已关闭') . "\n";
        $statusText .= "时间: " . ($user->telegram_notify_time ?? '23:50') . "\n\n";

        // 计算剩余天数
        if (!$user->expired_at) {
            $statusText .= "⏰ 服务状态: 永久有效";
        } else {
            $remainingDays = Carbon::now()->diffInDays(Carbon::parse($user->expired_at), false);
            if ($remainingDays > 0) {
                $statusText .= "⏰ 服务剩余: {$remainingDays} 天";
            } else {
                $statusText .= "⚠️ 服务已过期";
            }
        }
        
        $telegramService->sendMessage($msg->chat_id, $statusText, 'markdown');
    }
    
    private function escapeMarkdown($text)
    {
        $escapeChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        foreach ($escapeChars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }
        return $text;
    }
}