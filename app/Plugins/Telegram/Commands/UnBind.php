<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use Illuminate\Support\Facades\Cache;

class UnBind extends Telegram {
    public $command = '/unbind';
    public $description = '将Telegram账号从网站解绑';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $user = User::where('telegram_id', $message->chat_id)->first();
        $telegramService = $this->telegramService;
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }
        $oldTelegramId = $user->telegram_id;
        $user->telegram_id = NULL;
        if (!$user->save()) {
            abort(500, '解绑失败');
        }
        // 记录解绑信息，供绑定时检测是否更换了 TG 账号（1 小时窗口）
        Cache::put('tg_rebind_' . $user->id, [
            'old_telegram_id' => $oldTelegramId,
            'old_username'    => $message->from_username  ?? null,
            'old_first_name'  => $message->from_first_name ?? null,
            'email'           => $user->email,
        ], 3600);
        $telegramService->sendMessage($message->chat_id, '解绑成功', 'markdown');
    }
}