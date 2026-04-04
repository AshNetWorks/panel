<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\TelegramService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TelegramController extends Controller
{
    protected $msg;
    protected $commands = [];
    protected $telegramService;

    public function __construct(Request $request)
    {
        if ($request->input('access_token') !== md5(config('v2board.telegram_bot_token'))) {
            abort(401);
        }

        $this->telegramService = new TelegramService();
    }

    public function webhook(Request $request)
    {
        try {
            $data = $request->input();
            // 处理新成员加入事件（支持两种方式）
            // 方式1: message.new_chat_members (Privacy Mode关闭时)
            $this->handleNewChatMembers($data);

            // 方式2: chat_member (Privacy Mode开启但Bot是管理员时)
            $this->handleChatMemberUpdate($data);

            // 被动扫描：群成员发消息时检查资格（每人最多7天检查一次）
            $this->handleGroupMemberPassiveCheck($data);

            // 处理关键词自动回复（必须在命令处理之前）
            $this->handleKeywordAutoReply($data);

            // 处理 inline button 回调
            $this->handleCallbackQuery($data);

            // 处理普通消息和命令
            $this->formatMessage($data);
            $this->formatChatJoinRequest($data);
            $this->handle();

            return response('ok');
        } catch (\Exception $e) {
            \Log::error('Telegram webhook error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response('ok');
        }
    }

    public function handle()
    {
        if (!$this->msg) return;
        $msg = $this->msg;
        $commandName = explode('@', $msg->command);

        // To reduce request, only commands contains @ will get the bot name
        if (count($commandName) == 2) {
            $botName = $this->getBotName();
            if ($commandName[1] === $botName){
                $msg->command = $commandName[0];
            }
        }

        try {
            foreach (glob(base_path('app/Plugins/Telegram/Commands') . '/*.php') as $file) {
                $command = basename($file, '.php');
                $class = '\\App\\Plugins\\Telegram\\Commands\\' . $command;
                if (!class_exists($class)) continue;
                $instance = new $class();

                // 支持 regex 匹配（如 ReplyTicket - 用于回复工单）
                if (isset($instance->regex)) {
                    // 优先检查被回复消息的文本（用于回复工单场景）
                    $textToMatch = $msg->reply_text ?? $msg->text;
                    if (preg_match($instance->regex, $textToMatch, $match)) {
                        $instance->handle($msg, $match);
                        return;
                    }
                    continue;
                }

                // 支持普通 command 匹配
                if (!isset($instance->command)) continue;
                if ($msg->command !== $instance->command) continue;
                $instance->handle($msg);
                return;
            }
        } catch (\Exception $e) {
            $this->telegramService->sendMessage($msg->chat_id, $e->getMessage());
        }
    }

    public function getBotName()
    {
        $response = $this->telegramService->getMe();
        return $response->result->username;
    }

    private function formatMessage(array $data)
    {
        if (!isset($data['message'])) return;
        if (!isset($data['message']['text'])) return;
        $obj = new \StdClass();
        $text = explode(' ', $data['message']['text']);
        $obj->command = $text[0];
        $obj->args = array_slice($text, 1);
        $obj->chat_id = $data['message']['chat']['id'];
        $obj->message_id = $data['message']['message_id'];
        $obj->message_type = 'message';
        $obj->text = $data['message']['text'];
        $obj->is_private = $data['message']['chat']['type'] === 'private';

        // 添加发送者信息（重要！用于权限检查）
        if (isset($data['message']['from'])) {
            $obj->from_id = $data['message']['from']['id'];
            $obj->from_username = $data['message']['from']['username'] ?? null;
            $obj->from_first_name = $data['message']['from']['first_name'] ?? null;
        }

        // 处理回复消息，获取被回复用户的信息
        if (isset($data['message']['reply_to_message'])) {
            $obj->message_type = 'reply_message';
            $obj->reply_text = $data['message']['reply_to_message']['text'] ?? '';

            // 添加被回复用户的信息
            if (isset($data['message']['reply_to_message']['from'])) {
                $obj->reply_user_id = $data['message']['reply_to_message']['from']['id'];
                $obj->reply_username = $data['message']['reply_to_message']['from']['username'] ?? null;
                $obj->reply_first_name = $data['message']['reply_to_message']['from']['first_name'] ?? null;
            }
        }

        $this->msg = $obj;
    }

    private function formatChatJoinRequest(array $data)
    {
        if (!isset($data['chat_join_request'])) return;
        if (!isset($data['chat_join_request']['from']['id'])) return;
        if (!isset($data['chat_join_request']['chat']['id'])) return;
        $user = \App\Models\User::where('telegram_id', $data['chat_join_request']['from']['id'])
            ->first();
        if (!$user) {
            $this->telegramService->declineChatJoinRequest(
                $data['chat_join_request']['chat']['id'],
                $data['chat_join_request']['from']['id']
            );
            return;
        }
        $userService = new \App\Services\UserService();
        if (!$userService->isAvailable($user)) {
            $this->telegramService->declineChatJoinRequest(
                $data['chat_join_request']['chat']['id'],
                $data['chat_join_request']['from']['id']
            );
            return;
        }
        $this->telegramService->approveChatJoinRequest(
            $data['chat_join_request']['chat']['id'],
            $data['chat_join_request']['from']['id']
        );
    }

    /**
     * 处理 chat_member 更新事件（当Bot是管理员且开启Privacy Mode时）
     */
    private function handleChatMemberUpdate(array $data)
    {
        // 检查是否有 chat_member 更新
        if (!isset($data['chat_member'])) {
            return;
        }

        $chatMember = $data['chat_member'];
        $oldStatus = $chatMember['old_chat_member']['status'] ?? '';
        $newStatus = $chatMember['new_chat_member']['status'] ?? '';

        // 只处理新成员加入事件（从 left/kicked 变为 member/administrator/creator）
        $leftStatuses = ['left', 'kicked'];
        $joinedStatuses = ['member', 'administrator', 'creator', 'restricted'];

        if (!in_array($oldStatus, $leftStatuses) || !in_array($newStatus, $joinedStatuses)) {
            return;
        }

        $chatId = $chatMember['chat']['id'];
        $chatTitle = $chatMember['chat']['title'] ?? 'Unknown';
        $member = $chatMember['new_chat_member']['user'];

        // 跳过bot自己
        if (isset($member['is_bot']) && $member['is_bot']) {
            \Log::info('跳过Bot用户 (chat_member)', ['bot_username' => $member['username'] ?? 'unknown']);
            return;
        }

        $userId = $member['id'];
        $firstName = $member['first_name'] ?? '';
        $username = $member['username'] ?? '';

        // 创建可点击的用户链接（Markdown格式）
        $displayName = $username ?: $firstName;
        $userMention = "[{$displayName}](tg://user?id={$userId})";

        \Log::info('🎯 检测到新成员加入 (chat_member)', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'username' => $username,
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ]);

        // 使用统一的处理方法
        $this->processNewMember($chatId, $chatTitle, $userId, $firstName, $username, $userMention);
    }

    /**
     * 处理新成员加入群组事件（message.new_chat_members方式）
     */
    private function handleNewChatMembers(array $data)
    {
        // 检查是否有新成员加入
        if (!isset($data['message']['new_chat_members'])) {
            return;
        }

        $chatId = $data['message']['chat']['id'];
        $chatTitle = $data['message']['chat']['title'] ?? 'Unknown';
        $newMembers = $data['message']['new_chat_members'];

        \Log::info('🎯 检测到新成员加入 (new_chat_members)', [
            'chat_id' => $chatId,
            'chat_title' => $chatTitle,
            'members_count' => count($newMembers)
        ]);

        foreach ($newMembers as $member) {
            // 跳过bot自己
            if (isset($member['is_bot']) && $member['is_bot']) {
                \Log::info('跳过Bot用户 (new_chat_members)', ['bot_username' => $member['username'] ?? 'unknown']);
                continue;
            }

            $userId = $member['id'];
            $firstName = $member['first_name'] ?? '';
            $username = $member['username'] ?? '';

            // 创建可点击的用户链接（Markdown格式）
            $displayName = $username ?: $firstName;
            $userMention = "[{$displayName}](tg://user?id={$userId})";

            \Log::info('🚀 处理新成员 (new_chat_members)', [
                'user_id' => $userId,
                'username' => $username,
                'first_name' => $firstName
            ]);

            // 使用统一的处理方法
            $this->processNewMember($chatId, $chatTitle, $userId, $firstName, $username, $userMention);
        }
    }

    /**
     * 统一处理新成员加入的逻辑
     */
    private function processNewMember($chatId, $chatTitle, $userId, $firstName, $username, $userMention)
    {
        // 检查用户是否已绑定
        $user = \App\Models\User::where('telegram_id', $userId)->first();

        if (!$user) {
            // 用户未绑定，踢出群组
            \Log::warning('⚠️ 用户未绑定，准备踢出', ['user_id' => $userId]);
            $this->kickUnboundUser($chatId, $userId, $userMention);
            return;
        }

        // 检查用户状态
        if ($user->banned) {
            \Log::warning('⚠️ 用户已被封禁，准备踢出', ['user_id' => $userId, 'email' => $user->email]);
            $this->kickBannedUser($chatId, $userId, $userMention);
            return;
        }

        // 发送欢迎消息
        $welcomeMessage = $this->getWelcomeMessage($userMention);
        $welcomeButtons = $this->getWelcomeButtons();

        try {
            \Log::info('📤 发送欢迎消息', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'email' => $user->email
            ]);

            $response = $this->telegramService->sendMessage($chatId, $welcomeMessage, 'markdown', $welcomeButtons);

            if ($response && isset($response->ok) && $response->ok) {
                \Log::info('✅ 欢迎消息发送成功', [
                    'message_id' => $response->result->message_id ?? 'unknown'
                ]);

                // 5分钟后删除欢迎消息（给新用户足够时间阅读和点击按钮）
                if (isset($response->result->message_id)) {
                    \App\Jobs\DeleteTelegramMessageJob::dispatch($chatId, $response->result->message_id)
                        ->delay(now()->addSeconds(60));
                }
            } else {
                \Log::error('❌ 欢迎消息发送失败', [
                    'response' => json_encode($response, JSON_UNESCAPED_UNICODE)
                ]);
            }

            // 清除用户的邀请缓存
            \Illuminate\Support\Facades\Cache::forget("tg_invite_{$userId}");

        } catch (\Exception $e) {
            \Log::error('❌ 发送欢迎消息异常', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * 被动扫描：群成员发消息时检查账号/套餐资格
     * 每个 telegram_id 最多每 7 天检查一次，避免重复 DB 查询
     */
    private function handleGroupMemberPassiveCheck(array $data): void
    {
        if (!isset($data['message'])) return;

        $chatType = $data['message']['chat']['type'] ?? '';
        if (!in_array($chatType, ['group', 'supergroup'])) return;

        $from = $data['message']['from'] ?? null;
        if (!$from || !empty($from['is_bot'])) return;

        $userId = (int) $from['id'];
        $chatId = (int) $data['message']['chat']['id'];

        // 已检查通过 → 跳过（7天内不重复检查）
        $cacheKey = "tg_grp_verified:{$userId}";
        if (Cache::has($cacheKey)) return;

        // 群组管理员/群主无法被踢，直接跳过并缓存
        try {
            $member = $this->telegramService->getChatMember($chatId, $userId);
            if ($member && in_array($member->status ?? '', ['administrator', 'creator'])) {
                Cache::put($cacheKey, 1, now()->addDays(7));
                return;
            }
        } catch (\Exception) {
            // API 调用失败，继续后续检查
        }

        $displayName  = !empty($from['username']) ? '@' . $from['username'] : ($from['first_name'] ?? 'User');
        $userMention  = "[{$displayName}](tg://user?id={$userId})";

        $user = \App\Models\User::where('telegram_id', $userId)->first();

        if (!$user) {
            // 未绑定账号 → 尝试踢出
            // 若踢出失败（如 Telegram 管理员无法被踢），缓存 1 天避免重复查询
            $this->kickUnboundUser($chatId, $userId, $userMention);
            Cache::put($cacheKey, 0, now()->addDay());
            return;
        }

        if ($user->banned) {
            // 账号被封禁 → 尝试踢出，同上缓存 1 天
            $this->kickBannedUser($chatId, $userId, $userMention);
            Cache::put($cacheKey, 0, now()->addDay());
            return;
        }

        $userService = new UserService();
        if (!$userService->isAvailable($user)) {
            // 套餐过期或无套餐 → 尝试踢出，缓存 1 天
            $this->kickExpiredMember($chatId, $userId, $userMention, $user);
            Cache::put($cacheKey, 0, now()->addDay());
            return;
        }

        // 检查通过，缓存 7 天（到期后由下次发消息或定时任务重新检查）
        Cache::put($cacheKey, 1, now()->addDays(7));
    }

    /**
     * 踢出套餐过期/无套餐的现有群成员
     */
    private function kickExpiredMember($chatId, $userId, $userMention, $user): void
    {
        try {
            $banResult = $this->telegramService->banChatMember($chatId, $userId);

            if (!$banResult) return;

            // 踢出不永久拉黑
            $this->telegramService->unbanChatMember($chatId, $userId);

            // 群组通知（60秒后自动删除）
            $notificationText = "⚠️ 用户 {$userMention} 因套餐已到期或无有效套餐被移出群组\n\n"
                              . "💡 续费后私聊机器人发送 /join 可重新加入";
            $response = $this->telegramService->sendMessage($chatId, $notificationText, 'markdown');
            if (isset($response->result->message_id)) {
                \App\Jobs\DeleteTelegramMessageJob::dispatch($chatId, $response->result->message_id)
                    ->delay(now()->addSeconds(60));
            }

            // 私信通知用户（用户未开启私信时会抛异常，直接忽略）
            try {
                $expiredAt = $user->expired_at ? date('Y-m-d', $user->expired_at) : '无';
                $privateMsg = "🚪 <b>您已被移出群组</b>\n\n"
                            . "您的套餐" . ($user->plan_id ? "已于 <b>{$expiredAt}</b> 到期" : "不存在") . "，系统已自动将您移出群组。\n\n"
                            . "✅ 续费后私聊机器人发送 /join 可重新加入。\n\n"
                            . "如有疑问请联系客服。";
                $this->telegramService->sendMessage($userId, $privateMsg, 'html');
            } catch (\Exception $e) {
                // 忽略私信失败
            }

            \Log::info('✅ 套餐过期用户已踢出（消息触发）', [
                'user_id' => $userId,
                'email'   => $user->email,
            ]);
        } catch (\Exception $e) {
            \Log::error('❌ 踢出套餐过期群成员失败', [
                'error'   => $e->getMessage(),
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * 踢出未绑定用户
     */
    private function kickUnboundUser($chatId, $userId, $userMention)
    {
        try {
            // 封禁用户
            $banResult = $this->telegramService->banChatMember($chatId, $userId);

            if ($banResult) {
                // 立即解封（只踢出，不永久禁止）
                $this->telegramService->unbanChatMember($chatId, $userId);

                // 发送通知
                $notificationText = "⚠️ 用户 {$userMention} 因未绑定账户被移出群组\n\n";
                $notificationText .= "💡 如需加入群组，请：\n";
                $notificationText .= "1\\. 访问用户中心绑定 Telegram 账户\n";
                $notificationText .= "2\\. 绑定完成后私聊机器人发送 /join\n";
                $notificationText .= "3\\. 使用邀请链接重新加入群组";

                $response = $this->telegramService->sendMessage($chatId, $notificationText, 'markdown');

                if (isset($response->result->message_id)) {
                    \App\Jobs\DeleteTelegramMessageJob::dispatch($chatId, $response->result->message_id)
                        ->delay(now()->addSeconds(60));
                }

                \Log::info('✅ 未绑定用户已踢出', ['user_id' => $userId]);
            }
        } catch (\Exception $e) {
            \Log::error('❌ 踢出未绑定用户失败', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
        }
    }

    /**
     * 踢出被封禁用户
     */
    private function kickBannedUser($chatId, $userId, $userMention)
    {
        try {
            // 封禁用户
            $banResult = $this->telegramService->banChatMember($chatId, $userId);

            if ($banResult) {
                // 立即解封（只踢出，不永久禁止）
                $this->telegramService->unbanChatMember($chatId, $userId);

                // 发送通知
                $notificationText = "⚠️ 用户 {$userMention} 因账户被封禁而被移出群组\n\n";
                $notificationText .= "📞 如有疑问请联系管理员";

                $response = $this->telegramService->sendMessage($chatId, $notificationText, 'markdown');

                if (isset($response->result->message_id)) {
                    \App\Jobs\DeleteTelegramMessageJob::dispatch($chatId, $response->result->message_id)
                        ->delay(now()->addSeconds(60));
                }

                \Log::info('✅ 被封禁用户已踢出', ['user_id' => $userId]);
            }
        } catch (\Exception $e) {
            \Log::error('❌ 踢出被封禁用户失败', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
        }
    }

    /**
     * 获取欢迎消息
     */
    private function getWelcomeMessage(string $userMention): string
    {
        $appName = config('v2board.app_name', 'V2Board');
        $appUrl = config('v2board.app_url');

        return <<<EOT
🎉 欢迎 {$userMention} 加入 {$appName} 官方群组！

📋 *群组须知：*
• 本群仅供订阅用户交流使用
• 禁止发布违法违规内容
• 禁止发布广告和垃圾信息
• 请勿分享账号和订阅链接
• 遇到问题请先查看置顶消息
• 有问题请联系管理员

🤖 *私聊机器人可用功能：*
• /traffic - 查看流量使用情况
• /status - 查看账户状态
• /help - 查看所有可用命令

祝您使用愉快！🚀
EOT;
    }

    /**
     * 获取欢迎消息的按钮
     */
    private function getWelcomeButtons(): array
    {
        $appUrl = config('v2board.app_url');
        $telegramDiscussLink = config('v2board.telegram_discuss_link');

        return [
            'inline_keyboard' => [
                [
                    // 第一行：官网和文档按钮
                    [
                        'text' => '🌐 官方网站',
                        'url' => $appUrl
                    ],
                    [
                        'text' => '📖 使用文档',
                        'url' => $appUrl . '/docs'  // 可以修改为实际的文档地址
                    ]
                ],
                [
                    // 第二行：用户中心和客户端下载
                    [
                        'text' => '👤 用户中心',
                        'url' => $appUrl . '/dashboard'
                    ],
                    [
                        'text' => '💻 客户端下载',
                        'url' => $appUrl . '/download'  // 可以修改为实际的下载地址
                    ]
                ]
            ]
        ];
    }

    /**
     * 处理关键词自动回复
     */
    private function handleKeywordAutoReply(array $data)
    {
        // 只处理群组消息（不处理私聊）
        if (!isset($data['message'])) {
            return;
        }

        // 只处理文本消息
        if (!isset($data['message']['text'])) {
            return;
        }

        // 跳过命令消息（以 / 开头的）
        $text = trim($data['message']['text']);
        if (strpos($text, '/') === 0) {
            return;
        }

        // 只在群组中自动回复
        $chatType = $data['message']['chat']['type'] ?? '';
        if (!in_array($chatType, ['group', 'supergroup'])) {
            return;
        }

        $chatId    = $data['message']['chat']['id'];
        $messageId = $data['message']['message_id'];
        $fromId    = $data['message']['from']['id'] ?? null;

        // 群组管理员/群主发送的关键词消息不自动删除
        $senderIsAdmin = false;
        if ($fromId) {
            try {
                $member = $this->telegramService->getChatMember($chatId, $fromId);
                $senderIsAdmin = $member && in_array($member->status ?? '', ['administrator', 'creator']);
            } catch (\Exception) {
                // 查询失败，按普通用户处理
            }
        }

        // 定义关键词和回复内容
        $keywords = $this->getKeywordReplies();

        // 转换为小写进行匹配（不区分大小写）
        $textLower = mb_strtolower($text, 'UTF-8');

        foreach ($keywords as $keyword => $reply) {
            // 支持完全匹配和包含匹配
            if ($textLower === mb_strtolower($keyword, 'UTF-8') ||
                mb_strpos($textLower, mb_strtolower($keyword, 'UTF-8')) !== false) {

                \Log::info('🔔 触发关键词自动回复', [
                    'chat_id' => $chatId,
                    'keyword' => $keyword,
                    'original_text' => $text
                ]);

                try {
                    // 获取该关键词对应的按钮（如果有）
                    $buttons = $this->getKeywordButtons($keyword);

                    // 发送回复（使用 Markdown 格式，可能带按钮）
                    $response = $this->telegramService->sendMessage($chatId, $reply, 'markdown', $buttons);

                    if ($response && isset($response->ok) && $response->ok) {
                        \Log::info('✅ 关键词回复发送成功', [
                            'keyword' => $keyword,
                            'message_id' => $response->result->message_id ?? 'unknown'
                        ]);

                        // 管理员消息不自动删除，普通用户60秒后清理
                        if (!$senderIsAdmin) {
                            if (isset($response->result->message_id)) {
                                \App\Jobs\DeleteTelegramMessageJob::dispatch($chatId, $response->result->message_id)
                                    ->delay(now()->addSeconds(60));
                            }
                            \App\Jobs\DeleteTelegramMessageJob::dispatch($chatId, $messageId)
                                ->delay(now()->addSeconds(60));
                        }
                    }
                } catch (\Exception $e) {
                    \Log::error('❌ 关键词回复发送失败', [
                        'keyword' => $keyword,
                        'error' => $e->getMessage()
                    ]);
                }

                // 只回复第一个匹配的关键词
                break;
            }
        }
    }

    /**
     * 获取关键词自动回复配置
     *
     * @return array 关键词 => 回复内容
     */
    private function getKeywordReplies(): array
    {
        $appName = config('v2board.app_name', 'V2Board');
        $appUrl = config('v2board.app_url');
        $subscribeUrl = config('v2board.subscribe_url');

        return [
            '官网' => "🌐 *官方网站*\n\n访问地址: {$appUrl}\n\n请在官网注册并登录后使用服务",

            '客户端' => "💻 *推荐客户端*\n\n*Windows:* Clash for Windows / v2rayN\n*macOS:* ClashX / Surge\n*iOS:* Shadowrocket / Surge\n*Android:* Clash for Android / v2rayNG\n\n下载地址请访问官网文档",

            '帮助' => "❓ *获取帮助*\n\n*私聊机器人可用命令:*\n• /help \\- 查看所有命令\n• /traffic \\- 查看流量\n• /status \\- 查看账户状态\n\n*其他问题:*\n请联系管理员或查看置顶消息",

            'emby' => "🎬 *Emby 媒体服务*\n\n客户端、教程、图标都在下面\n请根据自己的需求选择客户端\n\n⚠️ *重要提示：*\n仅支持官方客户端及以下第三方客户端：femor、SenPlayer、Hills、HamHub、Conflux、Lenna、Filebar、Forward、Infuse（直连模式）、dscloud、Terminus Player、Tsukimi、Xfuse、Yamby、RodelPlayer、EplayerX\n\n🌍 *IP 限制说明：*\n中国、日本、美国以外的 IP 登录不受限制\n机场用户可使用日本、美国节点正常观看",
        ];
    }

    /**
     * 处理 inline button 回调（callback_query）
     */
    private function handleCallbackQuery(array $data): void
    {
        if (!isset($data['callback_query'])) return;

        $cq         = $data['callback_query'];
        $cqId       = $cq['id'];
        $fromId     = $cq['from']['id'] ?? null;
        $chatId     = $cq['message']['chat']['id'] ?? null;
        $messageId  = $cq['message']['message_id'] ?? null;
        $callbackData = $cq['data'] ?? '';

        if (!$fromId || !$chatId || !$messageId) return;

        // 只处理 sub_unban: 前缀的回调
        if (strpos($callbackData, 'sub_unban:') !== 0) return;

        // 格式：sub_unban:{confirm|cancel}:{targetUserId}
        $parts = explode(':', $callbackData);
        if (count($parts) !== 3) return;

        [, $action, $targetUserId] = $parts;
        $targetUserId = (int)$targetUserId;

        // 验证操作者是系统管理员
        $operator = \App\Models\User::where('telegram_id', $fromId)->where('is_admin', 1)->first();
        if (!$operator) {
            $this->telegramService->answerCallbackQuery($cqId, '❌ 权限不足', true);
            return;
        }

        $targetUser = \App\Models\User::find($targetUserId);
        if (!$targetUser) {
            $this->telegramService->answerCallbackQuery($cqId, '❌ 用户不存在', true);
            $this->telegramService->editMessageText($chatId, $messageId, '❌ 用户不存在，操作取消');
            return;
        }

        $banKey = 'sub:banned:' . $targetUserId;

        if ($action === 'confirm') {
            \Illuminate\Support\Facades\Redis::del($banKey);
            \Illuminate\Support\Facades\DB::table('v2_subscribe_pull_log')
                ->where('user_id', $targetUserId)
                ->where('created_at', '>=', now()->subHours(24))
                ->delete();

            \Illuminate\Support\Facades\DB::table('v2_subscribe_unban_log')->insert([
                'user_id'    => $targetUserId,
                'created_at' => now(),
            ]);

            $this->telegramService->answerCallbackQuery($cqId, '✅ 已解除封禁');
            $this->telegramService->editMessageText(
                $chatId, $messageId,
                "✅ 已解除 {$targetUser->email} 的订阅封禁\n操作人：{$operator->email}",
                'html'
            );

            // 通知被解封用户
            if ($targetUser->telegram_id) {
                try {
                    \App\Jobs\SendTelegramJob::dispatch(
                        $targetUser->telegram_id,
                        "✅ 您的订阅封禁已由管理员手动解除，现在可以正常拉取订阅了。"
                    );
                } catch (\Throwable) {}
            }

        } elseif ($action === 'cancel') {
            $this->telegramService->answerCallbackQuery($cqId, '已取消');
            $this->telegramService->editMessageText(
                $chatId, $messageId,
                "🚫 已取消解封操作\n用户：{$targetUser->email}",
                'html'
            );
        }
    }

    /**
     * 获取关键词回复的按钮
     *
     * @param string $keyword
     * @return array
     */
    private function getKeywordButtons(string $keyword): array
    {
        $appUrl = config('v2board.app_url');
        $keywordLower = mb_strtolower($keyword, 'UTF-8');

        // 官网相关 - 跳转到官网
        if ($keywordLower === '官网') {
            return [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '🌐 访问官网',
                            'url' => $appUrl
                        ]
                    ]
                ]
            ];
        }

        // 客户端相关 - 跳转到下载页面
        if ($keywordLower === '客户端') {
            return [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '💻 客户端下载',
                            'url' => $appUrl . '/download'
                        ]
                    ]
                ]
            ];
        }

        // 帮助 - 提供官网和文档
        if ($keywordLower === '帮助') {
            return [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '🌐 官方网站',
                            'url' => $appUrl
                        ],
                        [
                            'text' => '📖 使用文档',
                            'url' => $appUrl . '/docs'
                        ]
                    ]
                ]
            ];
        }

        // Emby相关 - 提供客户端、图标、教程
        if ($keywordLower === 'emby') {
            return [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '📱 Emby客户端',
                            'url' => 'https://t.me/PiPi32l/7'
                        ],
                        [
                            'text' => '🎨 Emby图标',
                            'url' => 'https://t.me/PiPi32l/25'
                        ]
                    ],
                    [
                        [
                            'text' => '📖 Emby注册教程',
                            'url' => 'https://oval-chef-6e8.notion.site/Emby-Ash-299c348e1f7c80e3bb8ccaa7ff2785ee?source=copy_link'
                        ]
                    ]
                ]
            ];
        }

        // 其他关键词不显示按钮
        return [];
    }
}
