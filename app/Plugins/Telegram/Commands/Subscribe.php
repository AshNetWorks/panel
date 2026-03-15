<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;

class Subscribe extends Telegram
{
    public $command = '/subscribe';
    public $description = '获取订阅链接';

    public function handle($message, $match = [])
    {
        $telegramService = $this->telegramService;

        // 检查是否在群组中使用
        if (!$message->is_private) {
            $text = "❌ 此命令只能在私聊中使用\n\n⚠️ 订阅链接包含敏感信息，请私聊机器人发送 /subscribe 获取";
            $telegramService->sendMessageWithAutoDelete($message->chat_id, $text, '', 60, $message->message_id);  // 群组中的错误提示，60秒后删除
            return;
        }

        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }

        $subscribeUrl = Helper::getSubscribeUrl($user->token);
        $text = "🔗订阅链接\n———————————————\n订阅地址：\n`{$subscribeUrl}`\n\n⚠️ 请勿将订阅链接分享给他人\n🕙 订阅链接有效期为7天";

        $telegramService->sendMessage($message->chat_id, $text, 'markdown');
    }
}
