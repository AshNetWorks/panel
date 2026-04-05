<?php

namespace App\Plugins\Telegram\Commands;

use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\WatchNotifyService;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class Bind extends Telegram {
    public $command = '/bind';
    public $description = '将Telegram账号绑定到网站';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        if (!isset($message->args[0])) {
            abort(500, '参数有误，请携带订阅地址发送');
        }
        $subscribeUrl = $message->args[0];
        $subscribeUrl = parse_url($subscribeUrl);
        parse_str($subscribeUrl['query'] ?? '', $query);
        $token = $query['token'] ?? null;
        if (!$token) {
            abort(500, '订阅地址无效');
        }

        // ✅ 移除伪装后缀（仅当配置了后缀时）
        $disguiseSuffix = config('v2board.subscribe_url_suffix');
        if ($disguiseSuffix && substr($token, -strlen($disguiseSuffix)) === $disguiseSuffix) {
            $token = substr($token, 0, -strlen($disguiseSuffix));
        }
        $submethod = (int)config('v2board.show_subscribe_method', 0);
        switch ($submethod) {
            case 0:
                break;
            case 1:
                if (!Cache::has("otpn_{$token}")) {
                    abort(403, 'token is error');
                }
                $usertoken = Cache::get("otpn_{$token}");
                $token = $usertoken;
                break;
            case 2:
                $usertoken = Cache::get("totp_{$token}");
                if (!$usertoken) {
                    $timestep = (int)config('v2board.show_subscribe_expire', 5) * 60;
                    $counter = floor(time() / $timestep);
                    $counterBytes = pack('N*', 0) . pack('N*', $counter);
                    $idhash = Helper::base64DecodeUrlSafe($token);
                    $parts = explode(':', $idhash, 2);
                    [$userid, $clienthash] = $parts;
                    if (!$userid || !$clienthash) {
                        abort(403, 'token is error');
                    }
                    $user = User::where('id', $userid)->select('token')->first();
                    if (!$user) {
                        abort(403, 'token is error');
                    }
                    $usertoken = $user->token;
                    $hash = hash_hmac('sha1', $counterBytes, $usertoken, false);
                    if ($clienthash !== $hash) {
                        abort(403, 'token is error');
                    }
                    Cache::put("totp_{$token}", $usertoken, $timestep);
                }
                $token = $usertoken;
                break;
            default:
                break;
        }
        $user = User::where('token', $token)->first();
        if (!$user) {
            abort(500, '用户不存在');
        }
        if ($user->telegram_id) {
            abort(500, '该账号已经绑定了Telegram账号');
        }
        $user->telegram_id = $message->chat_id;
        if (!$user->save()) {
            abort(500, '设置失败');
        }
        $telegramService = $this->telegramService;
        $telegramService->sendMessage($message->chat_id, '绑定成功');

        // 检测短时间内解绑后换绑新 TG 账号的行为
        $rebindKey = 'tg_rebind_' . $user->id;
        $rebindInfo = Cache::get($rebindKey);
        if ($rebindInfo && (int)$rebindInfo['old_telegram_id'] !== (int)$message->chat_id) {
            Cache::forget($rebindKey);
            $email      = $rebindInfo['email'];
            $oldTgLabel = $rebindInfo['old_username']
                ? '@' . $rebindInfo['old_username']
                : ($rebindInfo['old_first_name'] ?? '未知');
            $newTgLabel = ($message->from_username ?? null)
                ? '@' . $message->from_username
                : ($message->from_first_name ?? '未知');
            $notice = "⚠️ *TG 账号更换提醒*\n"
                . "👤 用户：`{$email}`\n"
                . "🔄 在 1 小时内解绑旧 TG 账号后绑定了新账号\n"
                . "📌 旧：`" . $rebindInfo['old_telegram_id'] . "` （{$oldTgLabel}）\n"
                . "📌 新：`" . $message->chat_id . "` （{$newTgLabel}）";
            foreach (WatchNotifyService::getAdminTelegramIds() as $adminId) {
                SendTelegramJob::dispatch($adminId, $notice);
            }
        }
    }
}
