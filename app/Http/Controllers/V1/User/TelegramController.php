<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    public function getBotInfo()
    {
        $telegramService = new TelegramService();
        $response = $telegramService->getMe();
        return response([
            'data' => [
                'username' => $response->result->username
            ]
        ]);
    }

    public function unbind(Request $request)
    {
        $user = User::where('user_id', $request->user['id'])->first();
    }

    public function getStatus(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, '用户不存在');
        }

        $bound = !empty($user->telegram_id);
        $inGroup = false;

        if ($bound) {
            $groupChatId = config('v2board.telegram_group_id');
            if ($groupChatId) {
                try {
                    $telegramService = new TelegramService();
                    $member = $telegramService->getChatMember($groupChatId, $user->telegram_id);
                    if ($member && isset($member->status)) {
                        $inGroup = in_array($member->status, ['member', 'administrator', 'creator', 'restricted']);
                    }
                } catch (\Exception $e) {
                    // getChatMember 失败时 inGroup 保持 false
                }
            }
        }

        return response([
            'data' => [
                'telegram_bound' => $bound,
                'in_group'       => $inGroup,
                'telegram_id'    => $bound ? $user->telegram_id : null,
            ]
        ]);
    }
}
