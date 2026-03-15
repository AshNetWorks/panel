<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Models\EmbyUser;
use App\Plugins\Telegram\Telegram;
use Carbon\Carbon;

class Emby extends Telegram
{
    public $command = '/emby';
    public $description = '查询Emby账户信息';

    public function handle($message, $match = [])
    {
        $telegramService = $this->telegramService;

        // 检查是否在群组中使用
        if (!$message->is_private) {
            $text = "❌ 此命令只能在私聊中使用\n\n⚠️ Emby账户信息包含敏感数据，请私聊机器人发送 /emby 查询";
            $telegramService->sendMessageWithAutoDelete($message->chat_id, $text, '', 60, $message->message_id);  // 群组中的错误提示，60秒后删除
            return;
        }

        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }

        // 查询用户的Emby账户
        $embyAccounts = EmbyUser::where('user_id', $user->id)
            ->with('embyServer')
            ->orderBy('created_at', 'desc')
            ->get();

        if ($embyAccounts->isEmpty()) {
            $telegramService->sendMessage($message->chat_id, '❌ 您还没有创建 Emby 账户\n\n请访问用户中心创建 Emby 账户', 'markdown');
            return;
        }

        // 发送Emby账户信息
        $this->sendEmbyAccountInfo($message->chat_id, $user, $embyAccounts);
    }

    private function sendEmbyAccountInfo($chatId, $user, $embyAccounts)
    {
        $message = "📺Emby 账户信息\n———————————————\n";

        foreach ($embyAccounts as $index => $account) {
            $serverName = $account->embyServer ? $account->embyServer->name : '未知服务器';
            $serverUrl = $account->embyServer ? $account->embyServer->url : '';

            $message .= "\n*账户 " . ($index + 1) . "*\n";
            $message .= "服务器：" . $serverName . "\n";
            $message .= "用户名：`" . $account->username . "`\n";

            // 解密并显示密码
            try {
                $password = decrypt($account->password);
                $message .= "密码：`" . $password . "`\n";
            } catch (\Exception $e) {
                $message .= "密码：无法获取\n";
            }

            if ($serverUrl) {
                $message .= "地址：`" . $serverUrl . "`\n";
            }

            if ($account->expired_at) {
                $expiredAt = Carbon::parse($account->expired_at)->format('Y-m-d H:i');
                $message .= "到期：" . $expiredAt . "\n";
            }

            $isActive = $account->isActive();
            $isExpired = $account->isExpired();

            if ($isActive) {
                $message .= "状态：✅ 正常\n";
            } else if ($isExpired) {
                $message .= "状态：⚠️ 已过期\n";
            } else {
                $message .= "状态：❌ 已禁用\n";
            }
        }

        $this->telegramService->sendMessage($chatId, $message, 'markdown');
    }
}
