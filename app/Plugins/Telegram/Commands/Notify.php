<?php

namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Models\User;

class Notify extends Telegram
{
    public $command = '/notify';
    public $description = '每日流量通知设置';

    public function handle($message, $match = [])
    {
        if (!$message->is_private) return;

        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, '❌ 未找到绑定的用户信息');
            return;
        }

        // 检查参数
        if (empty($message->args) || count($message->args) === 0) {
            $status = $user->telegram_daily_traffic_notify ? '🟢 已开启' : '🔴 已关闭';
            $notifyTime = $user->telegram_notify_time ?? '23:50';
            $helpText = "📋 *每日流量通知设置*\n\n";
            $helpText .= "当前状态：{$status}\n";
            $helpText .= "通知时间：`{$notifyTime}`\n\n";
            $helpText .= "使用方法：\n";
            $helpText .= "• `/notify on` - 开启通知\n";
            $helpText .= "• `/notify off` - 关闭通知\n";
            $helpText .= "• `/notify HH:MM` - 设置通知时间\n\n";
            $helpText .= "💡 提示：设置时间后会自动开启通知";
            $this->telegramService->sendMessage($message->chat_id, $helpText, 'markdown');
            return;
        }

        $action = strtolower($message->args[0]);

        if ($action === 'on') {
            $user->telegram_daily_traffic_notify = true;
            $user->save();
            $notifyTime = $user->telegram_notify_time ?? '23:50';
            $this->telegramService->sendMessage($message->chat_id, "✅ 每日流量通知已开启\n\n通知时间：{$notifyTime}\n如需修改时间请使用：/notify HH:MM");
        } elseif ($action === 'off') {
            $user->telegram_daily_traffic_notify = false;
            $user->save();
            $this->telegramService->sendMessage($message->chat_id, '❌ 每日流量通知已关闭');
        } elseif (preg_match('/^([0-1]?[0-9]|2[0-3]):([0-5][0-9])$/', $action)) {
            // 验证时间格式 HH:MM
            $user->telegram_notify_time = $action;
            $user->telegram_daily_traffic_notify = true; // 设置时间时自动开启通知
            $user->save();
            $this->telegramService->sendMessage($message->chat_id, "✅ 通知时间已设置为：{$action}\n每日流量通知已自动开启");
        } else {
            $this->telegramService->sendMessage($message->chat_id, "❌ 参数错误\n\n请使用：\n• /notify on - 开启通知\n• /notify off - 关闭通知\n• /notify HH:MM - 设置通知时间（如：23:50）");
        }
    }
}
