<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use Illuminate\Support\Facades\Cache;

class Join extends Telegram {
    public $command = '/join';
    public $description = '获取内部群组邀请链接';

    public function handle($message, $match = []) {
        try {
            // 1. 检查是否私聊 - 增强检查逻辑
            $isPrivate = ($message->is_private ?? false) || 
                        (isset($message->chat->type) && $message->chat->type === 'private');
            
            if (!$isPrivate) {
                $text = "❌ 此命令只能在私聊中使用\n\n请私聊机器人后重试";
                $this->telegramService->sendMessageWithAutoDelete(
                    $message->chat_id,
                    $text,
                    '',
                    60,  // 群组中的错误提示，60秒后删除
                    $message->message_id
                );
                return;
            }
            
            $telegramService = $this->telegramService;
            
            // 2. 查询用户
            try {
                $user = User::where('telegram_id', $message->chat_id)->first();
            } catch (\Exception $e) {
                \Log::error('查询用户失败', [
                    'chat_id' => $message->chat_id,
                    'error' => $e->getMessage()
                ]);
                $this->sendSafeMessage($telegramService, $message->chat_id, '❌ 系统错误，请稍后重试');
                return;
            }
            
            // 3. 基本验证
            if (!$user) {
                $this->sendSafeMessage($telegramService, $message->chat_id, 
                    '❌ 您尚未绑定账户，请先绑定后再使用此功能');
                return;
            }
            
            if ($user->banned) {
                $this->sendSafeMessage($telegramService, $message->chat_id, 
                    '❌ 您的账户已被封禁，无法获取群组邀请');
                return;
            }
            
            if ($this->isUserExpired($user)) {
                $this->sendSafeMessage($telegramService, $message->chat_id,
                    '❌ 您的账户已过期，请续费后再获取群组邀请');
                return;
            }

            // 4. 检查用户是否已经在群组中
            // 写死群组 ID（超级群组 ID 以 -100 开头）
            $groupChatId = config('v2board.telegram_group_id');

            if ($this->isUserInGroup($user->telegram_id, $groupChatId)) {
                $this->sendSafeMessage($telegramService, $message->chat_id, 
                    "✅ 您已经在群组中了！\n\n" .
                    "🎉 无需重复获取邀请链接\n" .
                    "💡 您可以直接在群组中交流和获取服务\n\n" .
                    "🔍 如果您没有找到群组，请检查：\n" .
                    "• 群组是否被折叠或归档\n" .
                    "• 是否在其他设备上登录"
                );
                return;
            }
            
            // 5. 检查是否有未使用的邀请链接
            $cacheKey = "tg_invite_{$user->telegram_id}";
            $existingInvite = Cache::get($cacheKey);
            
            if ($existingInvite && is_array($existingInvite) && isset($existingInvite['invite_link'])) {
                if ($this->isInviteValid($existingInvite)) {
                    $remainingTime = $this->getRemainingTime($existingInvite['expires_at']);
                    
                    $this->sendSafeMessage($telegramService, $message->chat_id, 
                        "🔗 您已有一个有效的邀请链接：\n\n" .
                        "{$existingInvite['invite_link']}\n\n" .
                        "⚠️ 此链接仅可使用一次，剩余有效期：{$remainingTime}\n" .
                        "💡 请使用现有链接，避免重复申请\n\n" .
                        "🚫 **注意**：重复申请不会生成新链接"
                    );
                    return;
                } else {
                    Cache::forget($cacheKey);
                }
            }
            
            // 6. 检查冷却
            $cooldownKey = "tg_join_cooldown_{$user->telegram_id}";
            if (Cache::has($cooldownKey)) {
                $remainingCooldown = Cache::get($cooldownKey . '_expires') - time();
                $this->sendSafeMessage($telegramService, $message->chat_id, 
                    "⏳ 操作太频繁，请 {$remainingCooldown} 秒后再试\n\n" .
                    "💡 提示：每次生成邀请链接后需要等待10秒"
                );
                return;
            }
            
            // 7. 创建邀请链接
            $inviteLink = $this->createInviteLink($user, $groupChatId);
            
            if ($inviteLink) {
                Cache::put($cooldownKey, true, 10);
                Cache::put($cooldownKey . '_expires', time() + 10, 10);
                
                $this->sendSafeMessage($telegramService, $message->chat_id, 
                    "🎉 群组邀请链接生成成功！\n\n" .
                    "🔗 **邀请链接：**\n" .
                    "{$inviteLink}\n\n" .
                    "⚠️ **重要提醒：**\n" .
                    "• 此链接仅限您个人使用\n" .
                    "• 链接仅可使用一次\n" .
                    "• 有效期为30分钟\n" .
                    "• 请勿分享给他人\n" .
                    "• 加入群组后链接自动失效\n\n" .
                    "💡 点击链接即可加入内部群组", 
                    'markdown'
                );
            } else {
                $this->sendSafeMessage($telegramService, $message->chat_id, 
                    '❌ 邀请链接生成失败，请稍后重试或联系管理员');
            }
            
        } catch (\Exception $e) {
            \Log::error('Join 命令执行失败', [
                'chat_id' => $message->chat_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            try {
                $this->sendSafeMessage(
                    $this->telegramService, 
                    $message->chat_id, 
                    '❌ 处理您的请求时出现错误，请稍后重试或联系管理员'
                );
            } catch (\Exception $sendError) {
                \Log::error('发送错误消息也失败了', [
                    'error' => $sendError->getMessage()
                ]);
            }
        }
    }

    /**
     * 安全发送消息 - 包含重试
     */
    private function sendSafeMessage($telegramService, $chatId, $message, $parseMode = '') {
        try {
            $result = $telegramService->sendMessage($chatId, $message, $parseMode);

            if (!$result || !$result->ok) {
                // 尝试不使用 markdown 重发
                if ($parseMode === 'markdown') {
                    $result = $telegramService->sendMessage($chatId, $message, '');
                }
            }

            return $result;

        } catch (\Exception $e) {
            return false;
        }
    }

    private function isUserInGroup($telegramUserId, $groupChatId) {
        try {
            $member = $this->telegramService->getChatMember($groupChatId, $telegramUserId);

            if ($member && isset($member->status)) {
                $inGroupStatuses = ['member', 'administrator', 'creator'];
                return in_array($member->status, $inGroupStatuses);
            }
        } catch (\Exception $e) {
            // 静默处理
        }

        return false;
    }

    private function getRemainingTime($expiresAt) {
        try {
            $expiresTimestamp = is_numeric($expiresAt) ? (int) $expiresAt : strtotime($expiresAt);

            if ($expiresTimestamp === false) {
                return '无法计算';
            }

            $remaining = $expiresTimestamp - time();

            if ($remaining <= 0) {
                return '已过期';
            }

            $minutes = floor($remaining / 60);
            $seconds = $remaining % 60;

            if ($minutes > 0) {
                return $minutes . '分钟' . ($seconds > 30 ? '30秒' : '');
            } else {
                return max(1, $seconds) . '秒';
            }
        } catch (\Exception $e) {
            return '计算失败';
        }
    }

    private function isInviteValid($inviteData) {
        try {
            if (!isset($inviteData['expires_at'])) {
                return false;
            }

            $expiresAt = $inviteData['expires_at'];
            $currentTime = time();

            if (is_string($expiresAt)) {
                $expiresTimestamp = strtotime($expiresAt);
                if ($expiresTimestamp === false) {
                    return false;
                }
            } elseif (is_numeric($expiresAt)) {
                $expiresTimestamp = (int) $expiresAt;
            } else {
                return false;
            }

            return $currentTime < $expiresTimestamp;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function isUserExpired($user) {
        try {
            $expiredAt = $user->expired_at;

            if (is_null($expiredAt)) {
                return false;
            }

            $expiredTimestamp = 0;

            if (is_string($expiredAt)) {
                $expiredTimestamp = strtotime($expiredAt);
                if ($expiredTimestamp === false) {
                    return false;
                }
            } elseif (is_numeric($expiredAt)) {
                $expiredTimestamp = (int) $expiredAt;
            } elseif (method_exists($expiredAt, 'getTimestamp')) {
                $expiredTimestamp = $expiredAt->getTimestamp();
            } else {
                return false;
            }

            return time() > $expiredTimestamp;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function createInviteLink($user, $groupChatId) {
        try {
            $expireTimestamp = time() + 1800;

            $response = $this->telegramService->createChatInviteLink($groupChatId, [
                'name' => "用户邀请 - {$user->email}",
                'expire_date' => $expireTimestamp,
                'member_limit' => 1,
                'creates_join_request' => false
            ]);

            if ($response && isset($response->invite_link)) {
                $cacheKey = "tg_invite_{$user->telegram_id}";
                $inviteData = [
                    'invite_link' => $response->invite_link,
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'created_at' => time(),
                    'expires_at' => $expireTimestamp
                ];

                Cache::put($cacheKey, $inviteData, 1800);  // 30分钟，与邀请链接有效期一致

                return $response->invite_link;
            }
        } catch (\Exception $e) {
            // 静默处理
        }

        return false;
    }
}