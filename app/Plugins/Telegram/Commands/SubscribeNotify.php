<?php

namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Models\User;

class SubscribeNotify extends Telegram
{
    public $command = '/subscribe_notify';
    public $description = '订阅拉取通知开关';

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
            $status = $user->telegram_subscribe_notify ? '🟢 已开启' : '🔴 已关闭';
            $helpText = "📋 订阅拉取通知设置\n\n";
            $helpText .= "当前状态：{$status}\n\n";
            $helpText .= "使用方法：\n";
            $helpText .= "• /subscribe_notify on - 开启通知\n";
            $helpText .= "• /subscribe_notify off - 关闭通知\n\n";
            $helpText .= "开启后，每次拉取订阅时将收到通知，包含设备信息、IP归属地等";
            $this->telegramService->sendMessage($message->chat_id, $helpText);
            return;
        }

        $action = strtolower($message->args[0]);

        if ($action === 'on') {
            $user->telegram_subscribe_notify = true;
            $user->save();
            $this->telegramService->sendMessage($message->chat_id, "✅ 订阅拉取通知已开启\n\n每次拉取订阅时将收到通知，包含设备信息、IP归属地等");
        } elseif ($action === 'off') {
            $user->telegram_subscribe_notify = false;
            $user->save();
            $this->telegramService->sendMessage($message->chat_id, '❌ 订阅拉取通知已关闭');
        } else {
            $this->telegramService->sendMessage($message->chat_id, "❌ 参数错误\n\n请使用：\n• /subscribe_notify on - 开启通知\n• /subscribe_notify off - 关闭通知");
        }
    }
}
