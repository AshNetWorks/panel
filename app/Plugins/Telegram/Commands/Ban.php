<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class Ban extends Telegram {
    public $command = '/ban';
    public $isAdmin = true;

    /**
     * 处理 /ban 命令
     * 使用方法：
     * 1. 回复要封禁的用户消息，然后发送 /ban [原因]
     * 2. 或者 /ban <用户ID> [原因]
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

            $targetUserId = null;
            $reason = '';

            // 方式1：从回复消息中获取用户ID
            if ($message->message_type === 'reply_message' && isset($message->reply_user_id)) {
                $targetUserId = (int)$message->reply_user_id;
                // 原因是所有参数
                $reason = !empty($message->args) ? implode(' ', $message->args) : '';
            }
            // 方式2：从参数中获取用户ID
            elseif (!empty($message->args[0])) {
                $targetUserId = (int)$message->args[0];
                // 原因是除第一个参数外的其他参数
                $reason = count($message->args) > 1 ? implode(' ', array_slice($message->args, 1)) : '';
            }

            if ($targetUserId) {
                $this->banUser($groupChatId, $targetUserId, $operatorId, $reason, $message->message_id);
            } else {
                $telegramService->sendMessageWithAutoDelete(
                    $message->chat_id,
                    "❌ 使用方法：\n\n" .
                    "方式1：回复用户消息后发送\n" .
                    "/ban [原因]\n\n" .
                    "方式2：直接指定用户ID\n" .
                    "/ban <用户ID> [原因]",
                    '',
                    60,
                    $message->message_id
                );
            }

        } catch (\Exception $e) {
            \Log::error('Ban 命令执行失败', [
                'error' => $e->getMessage(),
                'chat_id' => $message->chat_id ?? null
            ]);

            $telegramService->sendMessageWithAutoDelete($message->chat_id, '❌ 封禁失败：' . $e->getMessage(), '', 60, $message->message_id);
        }
    }

    /**
     * 执行封禁操作
     */
    private function banUser($groupChatId, $targetUserId, $operatorId, $reason = '', $userMessageId = null) {
        $telegramService = $this->telegramService;

        try {
            // 检查目标用户是否是管理员
            $targetMember = $telegramService->getChatMember($groupChatId, $targetUserId);
            if ($targetMember && in_array($targetMember->status, ['administrator', 'creator'])) {
                $telegramService->sendMessageWithAutoDelete($groupChatId, '❌ 无法封禁管理员', '', 60, $userMessageId);
                return;
            }

            // 封禁用户（永久）
            $result = $telegramService->banChatMember($groupChatId, $targetUserId, null, true);

            if ($result) {
                // 同时在数据库中标记用户为封禁状态
                $user = User::where('telegram_id', $targetUserId)->first();
                if ($user) {
                    $user->banned = 1;
                    $user->save();
                }

                $message = "✅ 用户已被踢出并拉黑\n\n" .
                    "👤 用户ID: {$targetUserId}\n" .
                    ($user ? "📧 邮箱: {$user->email}\n" : "");

                if ($reason) {
                    $message .= "📝 封禁原因: {$reason}\n";
                }

                $message .= "\n🚫 该用户已被永久封禁，无法再次加入群组";

                $telegramService->sendMessageWithAutoDelete($groupChatId, $message, '', 60, $userMessageId);
            } else {
                $telegramService->sendMessageWithAutoDelete($groupChatId, '❌ 封禁失败，请检查机器人权限', '', 60, $userMessageId);
            }

        } catch (\Exception $e) {
            \Log::error('封禁用户失败', [
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

            \Log::warning('无法获取成员状态', [
                'telegram_id' => $telegramId,
                'group_id' => $groupChatId,
                'member' => $member
            ]);

            return false;

        } catch (\Exception $e) {
            \Log::error('检查管理员权限失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'telegram_id' => $telegramId,
                'group_id' => $groupChatId
            ]);
            return false;
        }
    }
}
