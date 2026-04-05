<?php

namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Models\User;
use \Curl\Curl;
use Illuminate\Mail\Markdown;
use Carbon\Carbon;

class TelegramService {
    protected $api;

    public function __construct($token = '')
    {
        $this->api = 'https://api.telegram.org/bot' . config('v2board.telegram_bot_token', $token) . '/';
    }

    public function sendMessage(int $chatId, string $text, string $parseMode = '', array $replyMarkup = [])
    {
        if ($parseMode === 'markdown') {
            $text = str_replace('_', '\_', $text);
        }

        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode
        ];

        // 如果有按钮，添加 reply_markup
        if (!empty($replyMarkup)) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->request('sendMessage', $params);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false)
    {
        return $this->request('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text'              => $text,
            'show_alert'        => $showAlert,
        ]);
    }

    public function editMessageText(int $chatId, int $messageId, string $text, string $parseMode = '', array $replyMarkup = [])
    {
        if ($parseMode === 'markdown') {
            $text = str_replace('_', '\_', $text);
        }

        $params = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => $parseMode,
        ];
        if (!empty($replyMarkup)) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->request('editMessageText', $params);
    }

    public function deleteMessage(int $chatId, int $messageId)
    {
        return $this->request('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ]);
    }

    public function sendMessageWithAutoDelete(int $chatId, string $text, string $parseMode = '', int $deleteAfter = 30, int $userMessageId = null)
    {
        $response = $this->sendMessage($chatId, $text, $parseMode);

        if ($response && isset($response->result->message_id)) {
            $botMessageId = $response->result->message_id;

            // 创建延迟任务删除bot消息
            \App\Jobs\DeleteTelegramMessageJob::dispatch($chatId, $botMessageId)
                ->delay(now()->addSeconds($deleteAfter));

            // 如果提供了用户消息ID,也删除用户的命令消息
            if ($userMessageId) {
                \App\Jobs\DeleteTelegramMessageJob::dispatch($chatId, $userMessageId)
                    ->delay(now()->addSeconds($deleteAfter));
            }
        }

        return $response;
    }

    public function approveChatJoinRequest(int $chatId, int $userId)
    {
        return $this->request('approveChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function declineChatJoinRequest(int $chatId, int $userId)
    {
        return $this->request('declineChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function getMe()
    {
        return $this->request('getMe');
    }

    public function getWebhookInfo()
    {
        return $this->request('getWebhookInfo');
    }

    public function setWebhook(string $url)
    {
        try {
            // 发现并注册命令
            $commands = $this->discoverCommands(base_path('app/Plugins/Telegram/Commands'));

            if (!empty($commands)) {
                $this->setMyCommands($commands);
            }

            // 设置 webhook
            $result = $this->request('setWebhook', [
                'url' => $url,
                'allowed_updates' => json_encode([
                    'message',
                    'callback_query',
                    'chat_join_request',
                    'my_chat_member',
                    'chat_member'
                ])
            ]);

            return $result;

        } catch (\Exception $e) {
            \Log::error('设置 Webhook 异常', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    public function discoverCommands(string $directory): array
    {
        $commands = [];

        try {
            $files = glob($directory . '/*.php');

            if ($files === false || empty($files)) {
                return $commands;
            }

            foreach ($files as $file) {
                try {
                    $className = 'App\\Plugins\\Telegram\\Commands\\' . basename($file, '.php');

                    // 检查类是否已经存在，避免重复加载
                    if (!class_exists($className)) {
                        require_once $file;
                    }

                    if (!class_exists($className)) {
                        continue;
                    }

                    $ref = new \ReflectionClass($className);

                    if (
                        $ref->hasProperty('command') &&
                        $ref->hasProperty('description')
                    ) {
                        $commandProp = $ref->getProperty('command');
                        $descProp = $ref->getProperty('description');

                        $command = $commandProp->isStatic()
                            ? $commandProp->getValue()
                            : $ref->newInstanceWithoutConstructor()->command;

                        $description = $descProp->isStatic()
                            ? $descProp->getValue()
                            : $ref->newInstanceWithoutConstructor()->description;

                        // 确保命令以 / 开头
                        if (strpos($command, '/') === 0) {
                            $commands[] = [
                                'command' => ltrim($command, '/'),  // Telegram API 需要不带 / 的命令
                                'description' => $description,
                            ];
                        }
                    }
                } catch (\ReflectionException $e) {
                    \Log::error('反射命令类失败', [
                        'file' => $file,
                        'error' => $e->getMessage()
                    ]);
                    continue;
                } catch (\Exception $e) {
                    \Log::error('加载命令文件失败', [
                        'file' => $file,
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
            }
        } catch (\Exception $e) {
            \Log::error('发现命令过程失败', [
                'directory' => $directory,
                'error' => $e->getMessage()
            ]);
        }

        return $commands;
    }
    
    public function setMyCommands(array $commands)
    {
        $this->request('setMyCommands', [
            'commands' => json_encode($commands),
        ]);
    }

    // ✅ 改进的request方法，增加了错误处理和日志
    public function request(string $method, array $params = [])
    {
        try {
            $curl = new Curl();
            $curl->setTimeout(30);

            // 含文本或 reply_markup 时使用 POST，避免长文本超出 URL 长度限制
            if (isset($params['reply_markup']) || isset($params['text'])) {
                $curl->post($this->api . $method, $params);
            } else {
                $curl->get($this->api . $method . '?' . http_build_query($params));
            }

            $response = $curl->response;
            $httpCode = $curl->getHttpStatusCode();
            $curl->close();

            if ($httpCode !== 200) {
                \Log::error("Telegram API HTTP错误: {$httpCode}", [
                    'method' => $method,
                    'params' => $params
                ]);
                return false;
            }

            if (!isset($response->ok)) {
                \Log::error('Telegram API响应格式错误: ' . json_encode($response));
                return false;
            }

            if (!$response->ok) {
                \Log::error('Telegram API错误: ' . ($response->description ?? '未知错误'), [
                    'method' => $method,
                    'error_code' => $response->error_code ?? null
                ]);
                return false;
            }

            return $response;

        } catch (\Exception $e) {
            \Log::error('Telegram API请求异常: ' . $e->getMessage());
            return false;
        }
    }

    public function sendMessageWithAdmin($message, $isStaff = false)
    {
        if (!config('v2board.telegram_bot_enable', 0)) return;
        $users = User::where(function ($query) use ($isStaff) {
            $query->where('is_admin', 1);
            if ($isStaff) {
                $query->orWhere('is_staff', 1);
            }
        })
            ->where('telegram_id', '!=', NULL)
            ->get();
        foreach ($users as $user) {
            SendTelegramJob::dispatch($user->telegram_id, $message);
        }
    }

    // ✅ 新增：每日流量通知功能
    public function sendDailyTrafficNotification()
    {
        if (!config('v2board.telegram_bot_enable', 0)) {
            return ['total' => 0, 'success' => 0, 'failed' => 0];
        }
        
        $users = User::whereNotNull('telegram_id')
                    ->where('banned', 0)
                    ->where('expired_at', '>', Carbon::now())
                    ->where('telegram_daily_traffic_notify', 1)
                    ->get();
        
        $totalCount = count($users);
        $successCount = 0;
        $failedCount = 0;
        $results = [];
        
        foreach ($users as $user) {
            try {
                $message = $this->buildTrafficMessage($user);
                
                $result = $this->sendMessage($user->telegram_id, $message, 'markdown');
                
                if ($result && $result->ok) {
                    $successCount++;
                    $results[] = [
                        'user' => $user->email,
                        'telegram_id' => $user->telegram_id,
                        'status' => 'success'
                    ];
                } else {
                    $failedCount++;
                    $results[] = [
                        'user' => $user->email,
                        'telegram_id' => $user->telegram_id,
                        'status' => 'failed',
                        'error' => $result ? '未知错误' : 'API请求失败'
                    ];
                    \Log::error("发送流量通知失败给用户: {$user->email} (TG: {$user->telegram_id})");
                }
                
                usleep(300000); // 0.3秒
                
            } catch (\Exception $e) {
                $failedCount++;
                $results[] = [
                    'user' => $user->email,
                    'telegram_id' => $user->telegram_id,
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
                \Log::error("发送流量通知异常给用户: {$user->email} - " . $e->getMessage());
            }
        }
        
        return [
            'total' => $totalCount,
            'success' => $successCount,
            'failed' => $failedCount,
            'details' => $results
        ];
    }

    // ✅ 新增：构建流量消息
    private function buildTrafficMessage(User $user)
    {
        // 获取流量数据
        $todayUsed = $this->getTodayTrafficUsage($user);
        $yesterdayUsed = $this->getYesterdayTrafficUsage($user);
        $last7DaysUsed = $this->getLast7DaysTrafficUsage($user);
        
        // 计算总流量使用情况
        $totalUsed = $user->u + $user->d;
        $totalLimit = $user->transfer_enable;
        $remainingTraffic = $totalLimit - $totalUsed;
        $usagePercent = $totalLimit > 0 ? round(($totalUsed / $totalLimit) * 100, 2) : 0;
        
        $message = "📊 *每日流量报告*\n\n";
        $message .= "👋 你好 " . $this->escapeMarkdown($user->email) . "\n\n";
        $message .= "📅 日期: " . Carbon::now()->format('Y-m-d') . "\n\n";
        
        // 显示今日流量使用
        if ($todayUsed > 0) {
            $message .= "📈 今日已用: " . $this->formatBytes($todayUsed) . "\n";
        } else {
            // 如果今日数据为0，显示更有用的信息
            if ($yesterdayUsed > 0) {
                $message .= "📈 今日已用: 暂无数据 (昨日: " . $this->formatBytes($yesterdayUsed) . ")\n";
            } else {
                $avgDaily = $last7DaysUsed > 0 ? $last7DaysUsed / 7 : 0;
                if ($avgDaily > 0) {
                    $message .= "📈 今日已用: 暂无数据 (7日均: " . $this->formatBytes($avgDaily) . ")\n";
                } else {
                    $message .= "📈 今日已用: 暂无数据\n";
                }
            }
        }
        
        $message .= "📊 总计使用: " . $this->formatBytes($totalUsed) . "\n";
        $message .= "💾 流量套餐: " . $this->formatBytes($totalLimit) . "\n";
        $message .= "⚡ 剩余流量: " . $this->formatBytes($remainingTraffic) . "\n";
        $message .= "📋 使用率: " . $usagePercent . "%\n\n";
        
        // 根据使用率添加提醒
        if ($usagePercent >= 90) {
            $message .= "⚠️ *流量即将用完，请注意控制使用!*\n";
        } elseif ($usagePercent >= 80) {
            $message .= "🔔 流量使用已超过80%，请合理安排使用\n";
        } elseif ($usagePercent >= 50) {
            $message .= "ℹ️ 流量使用正常，请继续保持\n";
        } else {
            $message .= "✅ 流量充足，使用愉快\n";
        }
        
        // 显示历史数据
        if ($yesterdayUsed > 0 || $last7DaysUsed > 0) {
            $message .= "\n📈 *使用趋势*\n";
            if ($yesterdayUsed > 0) {
                $message .= "昨日使用: " . $this->formatBytes($yesterdayUsed) . "\n";
            }
            if ($last7DaysUsed > 0) {
                $avgDaily = $last7DaysUsed / 7;
                $message .= "7日平均: " . $this->formatBytes($avgDaily) . "\n";
            }
        }
        
        $message .= "\n📱 如需查看详细信息，请访问用户中心\n";
        $message .= "💬 回复 /notify_off 可关闭此通知";
        
        return $message;
    }

    // ✅ 新增：获取今日流量使用量（使用时间戳）
    private function getTodayTrafficUsage(User $user)
    {
        if (!\Schema::hasTable('v2_stat_user')) {
            return 0;
        }

        // 获取今日时间戳范围（从今天0点到当前时间）
        $todayStart = Carbon::today()->startOfDay()->timestamp;
        $todayEnd = Carbon::now()->timestamp;

        try {
            // 使用时间戳范围查询今日数据
            $todayStats = \DB::table('v2_stat_user')
                ->where('user_id', $user->id)
                ->where('record_at', '>=', $todayStart)
                ->where('record_at', '<=', $todayEnd)
                ->selectRaw('SUM(COALESCE(u, 0)) as total_u, SUM(COALESCE(d, 0)) as total_d, COUNT(*) as record_count')
                ->first();

            if ($todayStats && $todayStats->record_count > 0) {
                return $todayStats->total_u + $todayStats->total_d;
            }
            return 0;

        } catch (\Exception $e) {
            \Log::error("查询用户 {$user->id} 今日流量失败: " . $e->getMessage());
            return 0;
        }
    }

    // ✅ 新增：获取昨日流量使用量（使用时间戳）
    private function getYesterdayTrafficUsage(User $user)
    {
        if (!\Schema::hasTable('v2_stat_user')) {
            return 0;
        }

        // 获取昨日时间戳范围
        $yesterdayStart = Carbon::yesterday()->startOfDay()->timestamp;
        $yesterdayEnd = Carbon::yesterday()->endOfDay()->timestamp;

        try {
            $yesterdayStats = \DB::table('v2_stat_user')
                ->where('user_id', $user->id)
                ->where('record_at', '>=', $yesterdayStart)
                ->where('record_at', '<=', $yesterdayEnd)
                ->selectRaw('SUM(COALESCE(u, 0)) as total_u, SUM(COALESCE(d, 0)) as total_d, COUNT(*) as record_count')
                ->first();

            if ($yesterdayStats && $yesterdayStats->record_count > 0) {
                return $yesterdayStats->total_u + $yesterdayStats->total_d;
            }

        } catch (\Exception $e) {
            \Log::error("查询用户 {$user->id} 昨日流量失败: " . $e->getMessage());
        }

        return 0;
    }

    // ✅ 新增：获取最近7天流量使用量（使用时间戳）
    private function getLast7DaysTrafficUsage(User $user)
    {
        if (!\Schema::hasTable('v2_stat_user')) {
            return 0;
        }

        // 获取7天前到当前时间的时间戳范围
        $sevenDaysAgo = Carbon::today()->subDays(7)->startOfDay()->timestamp;
        $todayEnd = Carbon::now()->timestamp;  // 使用当前时间

        try {
            $weekStats = \DB::table('v2_stat_user')
                ->where('user_id', $user->id)
                ->where('record_at', '>=', $sevenDaysAgo)
                ->where('record_at', '<=', $todayEnd)
                ->selectRaw('SUM(COALESCE(u, 0)) as total_u, SUM(COALESCE(d, 0)) as total_d, COUNT(*) as record_count')
                ->first();

            if ($weekStats && $weekStats->record_count > 0) {
                return $weekStats->total_u + $weekStats->total_d;
            }

        } catch (\Exception $e) {
            \Log::error("查询用户 {$user->id} 7天流量失败: " . $e->getMessage());
        }

        return 0;
    }

    // ✅ 新增：格式化字节数
    private function formatBytes($bytes, $precision = 2)
    {
        if ($bytes == 0) return '0 B';
        
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    // ✅ 新增：转义Markdown字符
    private function escapeMarkdown($text)
    {
        $escapeChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        foreach ($escapeChars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }
        return $text;
    }

    // ✅ 新增：发送流量信息
    public function sendTrafficInfo(int $telegramId)
    {
        $user = User::where('telegram_id', $telegramId)->first();
        if (!$user) {
            return $this->sendMessage($telegramId, '❌ 未找到绑定的用户信息');
        }
        
        $message = $this->buildTrafficMessage($user);
        return $this->sendMessage($telegramId, $message, 'markdown');
    }

    // ✅ 新增：切换每日通知开关
    public function toggleDailyNotification(int $telegramId, bool $enabled)
    {
        $user = User::where('telegram_id', $telegramId)->first();
        if (!$user) {
            return $this->sendMessage($telegramId, '❌ 未找到绑定的用户信息');
        }
        
        $user->telegram_daily_traffic_notify = $enabled;
        $user->save();
        
        $message = $enabled ? "✅ 每日流量通知已开启" : "❌ 每日流量通知已关闭";
        return $this->sendMessage($telegramId, $message);
    }

    // ✅ 新增：设置通知时间
    public function setNotificationTime(int $telegramId, string $time)
    {
        $user = User::where('telegram_id', $telegramId)->first();
        if (!$user) {
            return $this->sendMessage($telegramId, '❌ 未找到绑定的用户信息');
        }
        
        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            return $this->sendMessage($telegramId, '❌ 时间格式错误，请使用 HH:MM 格式（如：09:30）');
        }
        
        $user->telegram_notify_time = $time;
        $user->save();
        
        return $this->sendMessage($telegramId, "✅ 通知时间已设置为 {$time}");
    }
    // =========== 群组邀请功能相关方法 ===========
    // 在你的 TelegramService 类中添加以下方法

    // 在 TelegramService.php 中，找到并替换以下方法：

    /**
     * 创建群组邀请链接
     */
    public function createChatInviteLink($chatId, $options = [])
    {
        $params = [
            'chat_id' => $chatId,
        ];
        
        // 添加可选参数
        if (isset($options['name'])) {
            $params['name'] = $options['name'];
        }
        
        if (isset($options['expire_date'])) {
            // 确保 expire_date 是整数时间戳
            if ($options['expire_date'] instanceof \Carbon\Carbon) {
                $params['expire_date'] = $options['expire_date']->timestamp;
            } elseif (is_string($options['expire_date'])) {
                $params['expire_date'] = \Carbon\Carbon::parse($options['expire_date'])->timestamp;
            } else {
                $params['expire_date'] = (int) $options['expire_date'];
            }
        }
        
        if (isset($options['member_limit'])) {
            $params['member_limit'] = (int) $options['member_limit'];
        }
        
        if (isset($options['creates_join_request'])) {
            $params['creates_join_request'] = $options['creates_join_request'] ? 'true' : 'false';
        }
        
        $response = $this->request('createChatInviteLink', $params);

        if ($response && $response->ok) {
            return $response->result;
        } else {
            \Log::error('创建邀请链接失败', [
                'response' => $response
            ]);
            return false;
        }
    }

    /**
     * 撤销邀请链接
     * @param string $chatId 群组ID
     * @param string $inviteLink 邀请链接
     * @return object|false
     */
    public function revokeChatInviteLink($chatId, $inviteLink)
    {
        $params = [
            'chat_id' => $chatId,
            'invite_link' => $inviteLink
        ];
        
        $response = $this->request('revokeChatInviteLink', $params);

        if ($response && $response->ok) {
            return $response->result;
        } else {
            \Log::error('撤销邀请链接失败', [
                'response' => $response
            ]);
            return false;
        }
    }

    /**
     * 获取群组信息
     * @param string $chatId 群组ID
     * @return object|false
     */
    public function getChat($chatId)
    {
        $params = [
            'chat_id' => $chatId
        ];
        
        $response = $this->request('getChat', $params);
        
        if ($response && $response->ok) {
            return $response->result;
        } else {
            \Log::error('获取群组信息失败', [
                'chat_id' => $chatId,
                'response' => $response
            ]);
            return false;
        }
    }

    /**
     * 检查用户是否在群组中以及权限状态
     * @param string $chatId 群组ID
     * @param int $userId 用户ID
     * @return object|false
     */
    public function getChatMember($chatId, $userId)
    {
        $params = [
            'chat_id' => $chatId,
            'user_id' => $userId
        ];
        
        $response = $this->request('getChatMember', $params);
        
        if ($response && $response->ok) {
            return $response->result;
        } else {
            \Log::error('获取群组成员信息失败', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'response' => $response
            ]);
            return false;
        }
    }

    /**
     * 获取机器人在群组中的权限信息
     * @param string $chatId 群组ID
     * @return array 权限信息
     */
    public function getBotPermissions($chatId)
    {
        try {
            // 获取机器人信息
            $botInfo = $this->getMe();
            if (!$botInfo || !$botInfo->ok) {
                return ['error' => '无法获取机器人信息'];
            }
            
            $botId = $botInfo->result->id;
            
            // 获取机器人在群组中的成员信息
            $member = $this->getChatMember($chatId, $botId);
            if (!$member) {
                return ['error' => '机器人不在群组中或无法获取权限信息'];
            }
            
            $status = $member->status ?? 'unknown';
            $permissions = [
                'status' => $status,
                'is_admin' => $status === 'administrator',
                'can_invite_users' => $member->can_invite_users ?? false,
                'can_delete_messages' => $member->can_delete_messages ?? false,
                'can_restrict_members' => $member->can_restrict_members ?? false,
                'can_manage_chat' => $member->can_manage_chat ?? false,
            ];
            
            return $permissions;
            
        } catch (\Exception $e) {
            \Log::error('检查机器人权限异常: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 验证群组邀请功能是否可用
     * @param string $chatId 群组ID
     * @return array 验证结果
     */
    public function validateInviteFunction($chatId)
    {
        $result = [
            'success' => false,
            'errors' => [],
            'warnings' => []
        ];
        
        // 检查群组ID格式
        if (!$chatId) {
            $result['errors'][] = '群组ID未配置';
            return $result;
        }
        
        if (!is_numeric($chatId) || $chatId >= 0) {
            $result['errors'][] = '群组ID格式错误，必须是负数';
            return $result;
        }
        
        // 检查机器人权限
        $permissions = $this->getBotPermissions($chatId);
        if (isset($permissions['error'])) {
            $result['errors'][] = '权限检查失败: ' . $permissions['error'];
            return $result;
        }
        
        if (!$permissions['is_admin']) {
            $result['errors'][] = '机器人不是群组管理员';
        }
        
        if (!$permissions['can_invite_users']) {
            $result['errors'][] = '机器人没有邀请用户权限';
        }
        
        // 检查缓存功能
        try {
            $testKey = 'invite_test_' . time();
            \Cache::put($testKey, 'test', 60);
            $cached = \Cache::get($testKey);
            if ($cached !== 'test') {
                $result['warnings'][] = '缓存功能异常';
            }
            \Cache::forget($testKey);
        } catch (\Exception $e) {
            $result['errors'][] = '缓存系统错误: ' . $e->getMessage();
        }
        
        // 如果没有错误，则功能可用
        if (empty($result['errors'])) {
            $result['success'] = true;
        }
        
        return $result;
    }

    /**
     * 获取群组邀请统计信息
     * @param string $chatId 群组ID
     * @return array 统计信息
     */
    public function getInviteStats($chatId)
    {
        try {
            // 获取群组信息
            $chatInfo = $this->getChat($chatId);
            
            // 统计活跃邀请
            $cachePattern = 'tg_invite_*';
            if (method_exists(\Cache::store(), 'getRedis')) {
                $keys = \Cache::store()->getRedis()->keys('laravel_cache:' . $cachePattern);
                $activeInvites = count($keys);
            } else {
                $activeInvites = 'N/A'; // 如果不是Redis，无法统计
            }
            
            return [
                'group_name' => $chatInfo ? $chatInfo->title : '未知',
                'group_id' => $chatId,
                'member_count' => $chatInfo ? ($chatInfo->member_count ?? 'N/A') : 'N/A',
                'active_invites' => $activeInvites,
                'bot_status' => 'active'
            ];
            
        } catch (\Exception $e) {
            \Log::error('获取邀请统计失败: ' . $e->getMessage());
            return [
                'error' => $e->getMessage()
            ];
        }
    }
    /**
     * 踢出/封禁群组成员
     * @param string $chatId 群组ID
     * @param int $userId 用户ID
     * @param int|null $untilDate 封禁到期时间（时间戳），null表示永久
     * @param bool $revokeMessages 是否撤销用户消息
     * @return object|false
     */
    public function banChatMember($chatId, $userId, $untilDate = null, $revokeMessages = false)
    {
        $params = [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'revoke_messages' => $revokeMessages ? 'true' : 'false'
        ];
        
        if ($untilDate !== null) {
            $params['until_date'] = $untilDate;
        }
        
        $response = $this->request('banChatMember', $params);

        if ($response && $response->ok) {
            return $response->result;
        } else {
            \Log::error('踢出用户失败', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'response' => $response
            ]);
            return false;
        }
    }

    /**
     * 踢出群组成员（不拉黑，用户可重新加入）
     * @param string $chatId 群组ID
     * @param int $userId 用户ID
     * @return bool
     */
    public function kickChatMember($chatId, $userId)
    {
        try {
            // 先封禁用户
            $banResult = $this->banChatMember($chatId, $userId, null, false);

            if (!$banResult) {
                return false;
            }

            // 立即解封（这样用户被踢出但可以重新加入）
            usleep(500000); // 等待0.5秒
            return $this->unbanChatMember($chatId, $userId, true) ? true : false;

        } catch (\Exception $e) {
            \Log::error('踢出用户异常', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 解封群组成员
     * @param string $chatId 群组ID
     * @param int $userId 用户ID
     * @param bool $onlyIfBanned 仅在用户被封禁时才解封
     * @return object|false
     */
    public function unbanChatMember($chatId, $userId, $onlyIfBanned = true)
    {
        $params = [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'only_if_banned' => $onlyIfBanned ? 'true' : 'false'
        ];
        
        $response = $this->request('unbanChatMember', $params);

        if ($response && $response->ok) {
            return $response->result;
        } else {
            \Log::error('解封用户失败', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'response' => $response
            ]);
            return false;
        }
    }

}