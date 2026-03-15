<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class Unban extends Telegram {
    public $command = '/unban';
    public $isAdmin = true;

    /**
     * 处理 /unban 命令
     * 使用方法：/unban <用户ID>
     */
    public function handle($message, $match = []) {
        $telegramService = $this->telegramService;

        // 检查是否在群组中使用
        if ($message->is_private) {
            $telegramService->sendMessage($message->chat_id, '❌ 此命令只能在群组中使用');
            return;
        }

        try {
            $groupChatId = $message->chat_id;

            // 获取命令发送者的 Telegram ID
            $operatorId = $message->from_id ?? null;
            if (!$operatorId) {
                $telegramService->sendMessageWithAutoDelete($message->chat_id, '❌ 无法识别命令发送者', '', 60, $message->message_id);
                return;
            }

            // 检查执行者是否是管理员
            if (!$this->isAdmin($operatorId, $groupChatId)) {
                $telegramService->sendMessageWithAutoDelete($message->chat_id, '❌ 只有管理员可以使用此命令', '', 60, $message->message_id);
                return;
            }

            // 从参数中获取用户ID
            if (empty($message->args[0])) {
                $telegramService->sendMessageWithAutoDelete(
                    $message->chat_id,
                    "❌ 使用方法：/unban <用户ID>",
                    '',
                    60,
                    $message->message_id
                );
                return;
            }

            $targetUserId = (int)$message->args[0];
            $this->unbanUser($groupChatId, $targetUserId, $message->message_id);

        } catch (\Exception $e) {
            \Log::error('Unban 命令执行失败', [
                'error' => $e->getMessage(),
                'chat_id' => $message->chat_id ?? null
            ]);

            $telegramService->sendMessageWithAutoDelete($message->chat_id, '❌ 解封失败：' . $e->getMessage(), '', 60, $message->message_id);
        }
    }

    /**
     * 执行解封操作
     */
    private function unbanUser($groupChatId, $targetUserId, $userMessageId = null) {
        $telegramService = $this->telegramService;

        try {
            // 解除群组封禁
            $result = $telegramService->unbanChatMember($groupChatId, $targetUserId, true);

            if ($result) {
                // 同时在数据库中解除封禁
                $user = User::where('telegram_id', $targetUserId)->first();
                if ($user) {
                    $user->banned = 0;
                    $user->save();
                }

                $telegramService->sendMessageWithAutoDelete(
                    $groupChatId,
                    "✅ 用户已解除封禁\n\n" .
                    "👤 用户ID: {$targetUserId}\n" .
                    ($user ? "📧 邮箱: {$user->email}\n" : "") .
                    "🎉 该用户现在可以重新加入群组",
                    '',
                    60,
                    $userMessageId
                );
            } else {
                $telegramService->sendMessageWithAutoDelete($groupChatId, '❌ 解封失败，请检查机器人权限', '', 60, $userMessageId);
            }

        } catch (\Exception $e) {
            \Log::error('解封用户失败', [
                'error' => $e->getMessage(),
                'group_id' => $groupChatId,
                'target_user_id' => $targetUserId
            ]);

            throw $e;
        }
    }

    /**
     * 检查用户是否是群组管理员
     * 只要是群组管理员即可，不需要系统管理员权限
     */
    private function isAdmin($telegramId, $groupChatId) {
        try {
            // 检查是否是群组管理员或创建者
            $member = $this->telegramService->getChatMember($groupChatId, $telegramId);

            if ($member && isset($member->status)) {
                return in_array($member->status, ['administrator', 'creator']);
            }

            return false;

        } catch (\Exception $e) {
            \Log::error('检查管理员权限失败', [
                'error' => $e->getMessage(),
                'telegram_id' => $telegramId,
                'group_id' => $groupChatId
            ]);
            return false;
        }
    }
}
