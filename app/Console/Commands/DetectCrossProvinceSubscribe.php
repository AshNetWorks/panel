<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Jobs\SendEmailJob;
use App\Jobs\SendTelegramJob;
use App\Utils\Helper;

class DetectCrossProvinceSubscribe extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscribe:detect-cross-province';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '检测用户跨省拉取订阅并自动重置订阅地址（仅检测中国大陆省份）';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // 1. 强制对齐时区
        date_default_timezone_set(config('app.timezone', 'Asia/Shanghai'));

        // 🟡 配置模式：建议先 true 跑一天，确认无误判后再改为 false
        $dryRun = true; 

        $this->info('🔍 开始检测跨省订阅行为（仅中国大陆省份）');
        if ($dryRun) {
            $this->warn('🧪 [DryRun] 测试模式：仅记录日志，不执行重置');
        }

        // 中国大陆省份白名单
        $mainlandProvinces = [
            '北京', '天津', '河北', '山西', '内蒙古',
            '辽宁', '吉林', '黑龙江',
            '上海', '江苏', '浙江', '安徽', '福建', '江西', '山东',
            '河南', '湖北', '湖南', '广东', '广西', '海南',
            '重庆', '四川', '贵州', '云南', '西藏',
            '陕西', '甘肃', '青海', '宁夏', '新疆'
        ];

        // 配置参数
        $timeWindow = 24; 
        $retentionDays = 30;
        
        // 生成绝对时间戳
        $currentTimestamp = time();
        $cutoffTimestamp = $currentTimestamp - ($timeWindow * 3600);
        $readableCutoff = date('Y-m-d H:i:s', $cutoffTimestamp);

        // 清理旧日志
        $this->cleanOldData($retentionDays);

        $this->info("⏰ 检测窗口: {$timeWindow}小时 (截止: {$readableCutoff})");
        $this->line('');

        // SQL 时间兼容逻辑 (兼容 Int 和 String 格式)
        $sqlLogTime = "IF(LENGTH(log.created_at)=10, log.created_at, UNIX_TIMESTAMP(log.created_at))";
        $sqlResetTime = "IF(LENGTH(reset.last_reset_at)=10, reset.last_reset_at, UNIX_TIMESTAMP(reset.last_reset_at))";

        // 🟢【核心优化】单次聚合查询
        // 直接查出每个人 24小时内 用了哪些国家、哪些省份、多少个IP
        $suspiciousCandidates = DB::table('v2_subscribe_pull_log as log')
            ->select(
                'log.user_id',
                DB::raw('COUNT(DISTINCT log.ip) as ip_count'),
                DB::raw('COUNT(*) as request_count'),
                DB::raw('GROUP_CONCAT(DISTINCT log.country) as country_list'),
                DB::raw('GROUP_CONCAT(DISTINCT log.province) as province_list')
            )
            ->leftJoin(
                DB::raw('(SELECT user_id, MAX(created_at) as last_reset_at FROM v2_subscribe_reset_log GROUP BY user_id) as reset'),
                'log.user_id', '=', 'reset.user_id'
            )
            // 时间过滤
            ->whereRaw("{$sqlLogTime} >= ?", [$cutoffTimestamp])
            ->where(function($query) use ($sqlLogTime, $sqlResetTime) {
                 $query->whereNull('reset.last_reset_at')
                       ->orWhereRaw("{$sqlLogTime} > {$sqlResetTime}");
            })
            // 初步过滤：只有 IP 数量 >= 2 的用户才值得分析，减少循环次数
            ->groupBy('log.user_id')
            ->having('ip_count', '>=', 2) 
            ->get();

        $this->info("📊 初步筛选出 {$suspiciousCandidates->count()} 个多IP用户，正在进行逻辑排除...");

        $resetCount = 0;
        $resetUsers = [];

        foreach ($suspiciousCandidates as $candidate) {
            $user = User::find($candidate->user_id);
            if (!$user) continue;

            // --- 🟢 核心逻辑：数据清洗与判断（仅跨省检测） ---

            // 1. 处理省份列表（只统计中国大陆的省份，使用白名单方式）
            $provinces = $candidate->province_list ? explode(',', $candidate->province_list) : [];

            // 使用白名单过滤，只保留中国大陆省份
            $provinces = array_filter($provinces, function($p) use ($mainlandProvinces) {
                // 过滤无效值
                if (in_array($p, ['未知', '内网', '', null])) {
                    return false;
                }

                // 检查是否匹配中国大陆省份白名单
                foreach ($mainlandProvinces as $province) {
                    if (strpos($p, $province) !== false) {
                        return true;
                    }
                }

                return false;
            });

            $provinces = array_unique($provinces);
            $provinceCount = count($provinces);

            // 2. IP 数量
            $ipCount = $candidate->ip_count;

            // --- 🟢 判定阈值（仅跨省和高频IP） ---
            $isCrossProvince = $provinceCount >= 3;      // 3个及以上省份
            $isHighFrequency = $ipCount >= 10;           // 10个及以上IP (高频)

            // 如果两项指标都正常，直接跳过
            if (!$isCrossProvince && !$isHighFrequency) {
                continue;
            }

            // --- 下面是确认违规后的处理流程 ---

            // 构建违规类型的文本描述
            $detectionType = [];
            $provinceStr = implode(',', array_slice($provinces, 0, 5));

            if ($isCrossProvince) {
                $detectionType[] = "跨省({$provinceCount}省):{$provinceStr}";
            }
            if ($isHighFrequency) {
                $detectionType[] = "高频IP({$ipCount}个)";
            }

            $detectionTypeStr = implode(' + ', $detectionType);

            // 🟡 DryRun 模式拦截
            if ($dryRun) {
                $this->info("🧪 [DryRun] 用户 {$user->email} 异常 [{$detectionTypeStr}]，未执行重置。");
                continue;
            }

            // 🔴 执行重置
            $oldToken = $user->token;
            $user->token = Helper::guid();
            $user->uuid = Helper::guid(true);
            $user->save();

            $this->info("🔄 已重置用户 {$user->email} [{$detectionTypeStr}]");

            // 记录到数据库
            DB::table('v2_subscribe_reset_log')->insert([
                'user_id' => $user->id,
                'old_token' => $oldToken,
                'new_token' => $user->token,
                'reason' => "检测到{$detectionTypeStr}",
                'province_count' => $provinceCount,
                'request_count' => $candidate->request_count,
                'provinces' => $provinceStr,
                'created_at' => time()
            ]);

            $resetCount++;
            
            // 准备通知数据
            $notifyDetails = [
                'province_count' => $provinceCount,
                'request_count' => $candidate->request_count,
                'provinces' => $provinceStr,
                'ip_count' => $ipCount
            ];
            
            // 添加到管理员通知列表
            $resetUsers[] = array_merge(['email' => $user->email, 'user_id' => $user->id, 'detection_type' => $detectionTypeStr], $notifyDetails);

            // 发送给用户
            $this->sendSecurityWarningEmail($user, $notifyDetails, $detectionTypeStr, $timeWindow);
            $this->sendUserTelegramNotification($user, $notifyDetails, $detectionTypeStr, $timeWindow);
        }

        // 发送给管理员
        if ($resetCount > 0) {
            $this->notifyAdmins($resetCount, $resetUsers, $timeWindow);
        }

        $this->info('✅ 检测完成');
        return 0;
    }

    /**
     * 发送安全警告邮件
     *
     * @param User $user
     * @param array $locationDetails
     * @param string $detectionType
     * @param int $timeWindow
     * @return void
     */
    private function sendSecurityWarningEmail($user, $locationDetails, $detectionType, $timeWindow)
    {
        try {
            $appName = config('v2board.app_name', 'V2Board');
            $appUrl = config('v2board.app_url');

            // 构建地理位置描述
            $locationDesc = [];
            if ($locationDetails['province_count'] >= 3) {
                $provinces = explode(',', $locationDetails['provinces']);
                $provincesText = implode('、', array_slice($provinces, 0, 5));
                if (count($provinces) > 5) {
                    $provincesText .= '等';
                }
                $locationDesc[] = "{$locationDetails['province_count']} 个不同省份（{$provincesText}）";
            }
            if ($locationDetails['ip_count'] >= 10) {
                $locationDesc[] = "{$locationDetails['ip_count']} 个不同IP地址";
            }
            $locationDescText = implode('和', $locationDesc);
            if (empty($locationDescText)) {
                $locationDescText = "多个异常位置";
            }

            $emailContent = "检测到您的订阅链接可能存在共享或泄露风险。\n\n";
            $emailContent .= "系统在过去{$timeWindow}小时内检测到您的订阅链接从 {$locationDescText} 被拉取了 {$locationDetails['request_count']} 次。\n\n";
            $emailContent .= "检测类型：{$detectionType}\n\n";
            $emailContent .= "为保障您的账号安全，系统已自动重置您的订阅链接。请重新获取订阅链接并更新您的客户端配置。\n\n";
            $emailContent .= "安全提示：\n";
            $emailContent .= "1. 请勿与他人共享您的订阅链接\n";
            $emailContent .= "2. 请勿与他人共享您的账号密码\n";
            $emailContent .= "3. 共享订阅、共享账号属于违规行为\n";
            $emailContent .= "4. 多次违规会导致账号被封禁，且不可解封、不退款\n";
            $emailContent .= "5. 请妥善保管您的账号信息，规范使用\n\n";
            $emailContent .= "如果这不是您本人的操作，请立即登录系统修改密码。\n";
            $emailContent .= "如有疑问，请联系客服。";

            $params = [
                'email' => $user->email,
                'subject' => config('v2board.app_name', 'V2Board') . ' - 订阅链接安全警告',
                'template_name' => 'securityWarning',
                'template_value' => [
                    'name' => $appName,
                    'url' => $appUrl,
                    'content' => $emailContent
                ]
            ];

            SendEmailJob::dispatch($params);

            $this->info("📧 安全警告邮件已发送至 {$user->email}");
        } catch (\Exception $e) {
            Log::error('发送安全警告邮件失败', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            $this->error("发送邮件失败: {$e->getMessage()}");
        }
    }

    /**
     * 发送用户Telegram通知
     *
     * @param User $user
     * @param array $locationDetails
     * @param string $detectionType
     * @param int $timeWindow
     * @return void
     */
    private function sendUserTelegramNotification($user, $locationDetails, $detectionType, $timeWindow)
    {
        try {
            // 检查用户是否绑定了Telegram
            if (!$user->telegram_id) {
                return;
            }

            $appName = config('v2board.app_name', 'V2Board');

            // 构建地理位置描述
            $locationDesc = [];
            if ($locationDetails['province_count'] >= 3) {
                $provinces = explode(',', $locationDetails['provinces']);
                $provincesText = implode('、', array_slice($provinces, 0, 5));
                if (count($provinces) > 5) {
                    $provincesText .= '等';
                }
                $locationDesc[] = "{$locationDetails['province_count']}个省份（{$provincesText}）";
            }
            if ($locationDetails['ip_count'] >= 10) {
                $locationDesc[] = "{$locationDetails['ip_count']}个IP地址";
            }
            $locationDescText = implode('和', $locationDesc);
            if (empty($locationDescText)) {
                $locationDescText = "多个异常位置";
            }

            // 构建Telegram消息
            $message = "🚨 订阅链接安全警告\n\n";
            $message .= "⚠️ 检测到您的订阅链接可能存在共享或泄露风险\n\n";
            $message .= "📊 检测详情：\n";
            $message .= "━━━━━━━━━━━━━━━\n";
            $message .= "⏰ 时间窗口：过去{$timeWindow}小时\n";
            $message .= "🌍 拉取来源：{$locationDescText}\n";
            $message .= "📈 拉取次数：{$locationDetails['request_count']}次\n";
            $message .= "🔍 检测类型：{$detectionType}\n";
            $message .= "━━━━━━━━━━━━━━━\n\n";
            $message .= "✅ 系统已自动重置您的订阅链接\n\n";
            $message .= "📝 请立即执行以下操作：\n";
            $message .= "1️⃣ 登录系统重新获取订阅链接\n";
            $message .= "2️⃣ 在所有客户端更新订阅配置\n";
            $message .= "3️⃣ 请勿与他人共享订阅链接\n";
            $message .= "4️⃣ 请勿与他人共享账号密码\n\n";
            $message .= "⚠️ 重要提示：\n";
            $message .= "• 共享订阅/账号属于违规行为\n";
            $message .= "• 多次违规将导致账号被封禁\n";
            $message .= "• 封禁账号不可解封且不退款\n\n";
            $message .= "❓ 如非本人操作，请立即修改密码\n";
            $message .= "💬 如有疑问，请联系客服\n\n";
            $message .= "━━━━━━━━━━━━━━━\n";
            $message .= "📅 " . now()->format('Y-m-d H:i:s');

            // 发送Telegram消息
            SendTelegramJob::dispatch($user->telegram_id, $message);

            $this->info("📱 已发送Telegram通知至用户 {$user->email} (TG ID: {$user->telegram_id})");

            Log::info('用户Telegram安全警告已发送', [
                'user_id' => $user->id,
                'email' => $user->email,
                'telegram_id' => $user->telegram_id,
                'detection_type' => $detectionType
            ]);
        } catch (\Exception $e) {
            Log::error('发送用户Telegram通知失败', [
                'user_id' => $user->id,
                'telegram_id' => $user->telegram_id ?? null,
                'error' => $e->getMessage()
            ]);
            // 不抛出异常，避免影响邮件发送
        }
    }

    /**
     * 清理30天前的订阅重置日志
     *
     * @param int $retentionDays
     * @return void
     */
    private function cleanOldData($retentionDays)
    {
        try {
            $cutoffTime = now()->subDays($retentionDays)->timestamp;

            // 只清理订阅重置日志（订阅拉取日志由 subscribe:cleanup 命令统一管理）
            $deletedResetLogs = DB::table('v2_subscribe_reset_log')
                ->where('created_at', '<', $cutoffTime)
                ->delete();

            if ($deletedResetLogs > 0) {
                $this->info("🗑️ 已清理 {$deletedResetLogs} 条超过{$retentionDays}天的订阅重置日志");
                Log::info("清理订阅重置日志", [
                    'deleted_count' => $deletedResetLogs,
                    'retention_days' => $retentionDays
                ]);
            } else {
                $this->info("✅ 没有需要清理的重置日志（保留{$retentionDays}天）");
            }
        } catch (\Exception $e) {
            Log::error('清理重置日志失败', [
                'error' => $e->getMessage()
            ]);
            $this->error("清理重置日志失败: {$e->getMessage()}");
        }
    }

    /**
     * 通知管理员
     *
     * @param int $resetCount
     * @param array $resetUsers
     * @param int $timeWindow
     * @return void
     */
    private function notifyAdmins($resetCount, $resetUsers, $timeWindow)
    {
        try {
            $adminTelegramId = $this->getSystemAdminTelegramId();

            if (!$adminTelegramId) {
                Log::warning('未找到管理员 Telegram ID，跨省订阅检测通知未发送');
                return;
            }

            // 构建通知消息
            $message = "⚠️ 订阅安全警告\n\n";
            $message .= "🔍 检测到 {$resetCount} 个用户存在跨省订阅异常\n";
            $message .= "⏰ 检测时间窗口：{$timeWindow} 小时\n";
            $message .= "📅 检测时间：" . now()->format('Y-m-d H:i:s') . "\n\n";

            $message .= "📋 异常用户列表：\n";
            $message .= str_repeat("─", 30) . "\n";

            foreach ($resetUsers as $index => $userData) {
                if ($index >= 10) {
                    $remaining = count($resetUsers) - 10;
                    $message .= "\n... 还有 {$remaining} 个用户\n";
                    break;
                }

                $locationInfo = [];

                // 省份信息
                if (!empty($userData['provinces'])) {
                    $provinces = explode(',', $userData['provinces']);
                    $provincesText = implode('、', array_slice($provinces, 0, 3));
                    if (count($provinces) > 3) {
                        $provincesText .= '等';
                    }
                    $locationInfo[] = "🗺️ {$userData['province_count']}省({$provincesText})";
                }

                $message .= "\n👤 " . ($index + 1) . ". {$userData['email']}\n";
                $message .= "   ID: {$userData['user_id']}\n";
                $message .= "   类型：{$userData['detection_type']}\n";
                $message .= "   " . implode(' ', $locationInfo) . "\n";
                $message .= "   🌐 不同IP数：{$userData['ip_count']} | 总拉取：{$userData['request_count']}次\n";
            }

            $message .= "\n" . str_repeat("─", 30) . "\n";
            $message .= "✅ 已自动重置这些用户的订阅链接\n";
            $message .= "📧 已发送安全警告邮件给用户";

            // 发送 Telegram 消息
            SendTelegramJob::dispatch($adminTelegramId, $message);

            $this->info("📱 已发送 Telegram 通知给管理员");

            Log::info('跨省订阅检测通知已发送', [
                'telegram_id' => $adminTelegramId,
                'reset_count' => $resetCount
            ]);
        } catch (\Exception $e) {
            Log::error('发送跨省订阅检测通知失败', [
                'error' => $e->getMessage(),
                'reset_count' => $resetCount
            ]);
            $this->error("发送 Telegram 通知失败: {$e->getMessage()}");
        }
    }

    /**
     * 获取用户的地理位置详细信息（只统计最后一次重置后的记录）
     *
     * @param int $userId
     * @param mixed $cutoffTime
     * @return array
     */
    /**
     * 获取用户详情（修复版：强制兼容时间戳）
     */
    private function getUserLocationDetails($userId, $cutoffTimestamp)
    {
        // 1. 获取最后重置时间
        $lastReset = DB::table('v2_subscribe_reset_log')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->first();

        // 2. 基础查询
        $query = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $userId);

        // 3. 强制兼容：无论数据库是 int 还是 string，都转为时间戳比较
        // 这里的 cutoffTimestamp 是我们在 handle 里算好的 int 类型
        $query->whereRaw("IF(LENGTH(created_at)=10, created_at, UNIX_TIMESTAMP(created_at)) >= ?", [$cutoffTimestamp]);

        // 4. 如果有重置记录，只查重置后的
        if ($lastReset) {
            // 确保 lastReset 也是时间戳
            $resetTs = is_numeric($lastReset->created_at) ? $lastReset->created_at : strtotime($lastReset->created_at);
            
            // SQL 比较
            $query->whereRaw("IF(LENGTH(created_at)=10, created_at, UNIX_TIMESTAMP(created_at)) > ?", [$resetTs]);
        }

        $logs = $query->get();

        // 5. 统计与过滤
        $countries = $logs->whereNotIn('country', ['未知', '内网', '', null])->pluck('country')->unique();
        $provinces = $logs->whereNotIn('province', ['未知', '内网', '', null])->pluck('province')->unique();
        $uniqueIps = $logs->pluck('ip')->unique();

        return [
            'country_count' => $countries->count(),
            'province_count' => $provinces->count(),
            'ip_count' => $uniqueIps->count(),
            'request_count' => $logs->count(),
            'countries' => $countries->join(','),
            'provinces' => $provinces->join(',')
        ];
    }

    /**
     * 获取管理员 Telegram ID
     *
     * @return int|null
     */
    private function getSystemAdminTelegramId()
    {
        try {
            // 首先尝试获取管理员
            $adminUser = DB::table('v2_user')
                ->where('is_admin', 1)
                ->whereNotNull('telegram_id')
                ->where('banned', 0)
                ->first();

            if ($adminUser && $adminUser->telegram_id) {
                Log::info('从用户表获取到管理员 Telegram ID', [
                    'admin_email' => $adminUser->email,
                    'telegram_id' => $adminUser->telegram_id
                ]);
                return $adminUser->telegram_id;
            }

            // 如果没有管理员，尝试获取 staff
            $staffUser = DB::table('v2_user')
                ->where('is_staff', 1)
                ->whereNotNull('telegram_id')
                ->where('banned', 0)
                ->first();

            if ($staffUser && $staffUser->telegram_id) {
                Log::info('从用户表获取到 staff Telegram ID', [
                    'staff_email' => $staffUser->email,
                    'telegram_id' => $staffUser->telegram_id
                ]);
                return $staffUser->telegram_id;
            }

            Log::warning('未找到任何管理员或 staff 的 Telegram ID');
            return null;
        } catch (\Exception $e) {
            Log::error('获取管理员 Telegram ID 失败', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 获取截止时间（自动检测created_at字段格式）
     *
     * @param int $hours
     * @return int|string
     */
    private function getCutoffTime($hours)
    {
        try {
            // 获取一条最新记录来检测created_at字段格式
            $sampleLog = DB::table('v2_subscribe_pull_log')
                ->orderBy('id', 'desc')
                ->first();

            if (!$sampleLog) {
                // 如果没有记录，默认使用时间戳格式
                return now()->subHours($hours)->timestamp;
            }

            // 判断created_at是时间戳还是datetime格式
            if (is_numeric($sampleLog->created_at)) {
                // Unix时间戳格式
                $this->info('✅ 检测到created_at使用Unix时间戳格式');
                return now()->subHours($hours)->timestamp;
            } else {
                // datetime格式
                $this->info('✅ 检测到created_at使用datetime格式');
                return now()->subHours($hours);
            }
        } catch (\Exception $e) {
            Log::error('检测created_at格式失败', ['error' => $e->getMessage()]);
            // 默认返回时间戳格式
            return now()->subHours($hours)->timestamp;
        }
    }
}