<?php

// ============ 修改后的Emby用户控制器 ============
// 实现：用户创建成功后，已创建的服务器仍显示在列表中，按钮变为不可点击状态，隐藏服务器IP地址

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\EmbyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class EmbyController extends Controller
{
    protected $embyService;

    public function __construct(EmbyService $embyService)
    {
        $this->embyService = $embyService;
    }

    // ============ 普通用户功能 ============

    /**
     * 获取用户Emby信息
     */
    public function fetch(Request $request)
    {
        $userId = $request->user['id'] ?? null;
        if (!$userId) {
            abort(500, '用户不存在');
        }
    
        // 获取用户账号列表
        $accounts = DB::table('v2_emby_users as eu')
            ->leftJoin('v2_emby_servers as es', 'eu.emby_server_id', '=', 'es.id')
            ->where('eu.user_id', $userId)
            ->select([
                'eu.id',
                'eu.emby_server_id as server_id',
                'eu.emby_user_id',
                'eu.username',
                'eu.password',
                'eu.expired_at',
                'eu.status',
                'eu.created_at',
                'es.name as server_name',
                'es.url as server_url'
            ])
            ->orderBy('eu.created_at', 'desc')
            ->get()
            ->toArray();
    
        // 处理账号数据
        foreach ($accounts as &$account) {
            $account = (array)$account;
            $account['expired_at'] = $account['expired_at'] ? strtotime($account['expired_at']) : null;
            $account['created_at'] = $account['created_at'] ? strtotime($account['created_at']) : null;
            $account['is_expired'] = $account['expired_at'] ? time() > $account['expired_at'] : false;
            $account['is_active'] = $account['status'] && !$account['is_expired'];
            
            // 解密密码
            try {
                $account['password'] = $account['password'] ? decrypt($account['password']) : null;
            } catch (\Exception $e) {
                // 如果解密失败（可能是旧的bcrypt密码），设为null
                $account['password'] = null;
                \Log::warning('密码解密失败', [
                    'account_id' => $account['id'],
                    'error' => $e->getMessage()
                ]);
            }
            
        }
    
        // 获取用户基本订阅信息
        $userBasic = DB::table('v2_user')
            ->where('id', $userId)
            ->select(['plan_id', 'expired_at'])
            ->first();

        $hasPlan = $userBasic && !empty($userBasic->plan_id);
        $expiredAtTs = $userBasic && $userBasic->expired_at ? (int)$userBasic->expired_at : 0;
        $isExpired = $expiredAtTs > 0 && $expiredAtTs <= time();

        // 获取所有有权限的服务器（包括已创建账户的）
        // 过期情况下也返回服务器列表用于展示，但前端将禁用创建按钮
        $availableServers = $this->getAllAvailableServers($userId, true);
    
        // 返回响应
        $response = [
            'data' => [
                'accounts' => $accounts,
                'available_servers' => $availableServers,
                'is_admin' => $this->isAdmin($request),
                'has_plan' => (bool)$hasPlan,
                'is_expired' => (bool)$isExpired,
                'debug_info' => [
                    'user_id' => $userId,
                    'accounts_count' => count($accounts),
                    'available_servers_count' => count($availableServers),
                    'timestamp' => time()
                ]
            ]
        ];
    
    
        return response($response);
    }

    /**
     * 续期后触发当前用户的 Emby 同步（异步，10 分钟节流）
     */
    public function syncSelf(Request $request)
    {
        $userId = $request->user['id'] ?? null;
        if (!$userId) {
            abort(401, '未登录');
        }

        try {
            $cacheKey = 'emby:sync:user:' . $userId;
            // 10 分钟内只触发一次
            if (!\Cache::add($cacheKey, 1, 600)) {
                return response(['data' => ['message' => '已在队列中，稍后生效']]);
            }

            // 若有队列系统，建议改为 dispatch(Job)
            // 这里直接异步触发（如果无队列可直接调用，但建议不要阻塞请求）
            try {
                $this->embyService->syncUserExpiration($userId);
            } catch (\Throwable $e) {
                \Log::error('syncSelf 执行同步失败', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }

            return response(['data' => ['message' => '已提交同步']]);
        } catch (\Throwable $e) {
            abort(500, '提交同步失败：' . $e->getMessage());
        }
    }

    /**
     * 创建Emby账号
     */
    public function save(Request $request)
    {
        $userId = $request->user['id'] ?? null;
        if (!$userId) {
            abort(500, '用户不存在');
        }
    
        $serverId = (int)$request->input('server_id');
        if (!$serverId) {
            abort(422, '请选择服务器');
        }
    
        // 频控：按服务器，创建账户每6小时仅可一次
        $recentCreate = DB::table('v2_emby_logs')
            ->where('user_id', $userId)
            ->where('emby_server_id', $serverId)
            ->where('action', 'create')
            ->where('created_at', '>=', now()->subHours(6))
            ->first();
        if ($recentCreate) {
            abort(429, '创建账号频率限制：同一服务器每6小时仅可创建一次');
        }
    
        // 检查服务器是否存在且启用
        $server = DB::table('v2_emby_servers')
            ->where('id', $serverId)
            ->where('status', 1)
            ->first();
    
        if (!$server) {
            abort(422, '选择的服务器不存在或已禁用');
        }
    
        // 检查是否已经有账号
        $existingAccount = DB::table('v2_emby_users')
            ->where('user_id', $userId)
            ->where('emby_server_id', $serverId)
            ->first();
    
        if ($existingAccount) {
            abort(422, '您已经在此服务器上拥有账号');
        }
    
        // 获取用户详细信息
        $user = DB::table('v2_user')
            ->where('id', $userId)
            ->select(['id', 'plan_id', 'expired_at', 'banned'])
            ->first();
        
        if (!$user) {
            abort(500, '无法获取用户信息');
        }
    
        // V2Board的expired_at字段已经是时间戳格式
        $expiredAtTimestamp = (int)$user->expired_at;
    
        // 详细检查订阅状态
        if (!$user->plan_id) {
            abort(422, '用户没有有效的套餐计划');
        }
    
        if (!$expiredAtTimestamp) {
            abort(422, '用户没有设置到期时间');
        }
    
        if ($expiredAtTimestamp <= time()) {
            abort(422, '用户订阅已过期，过期时间: ' . date('Y-m-d H:i:s', $expiredAtTimestamp));
        }
    
        if ($user->banned == 1) {
            abort(422, '用户账号已被禁用');
        }

        // 检查年费套餐要求
        if ($server->require_yearly) {
            $yearlyPeriods = ['year_price', 'two_year_price', 'three_year_price'];
            $periodMonths = ['year_price' => 12, 'two_year_price' => 24, 'three_year_price' => 36];
            $passYearlyCheck = false;

            // 条件1：存在一笔年付订单且该订单计算到期时间未过期
            $yearlyOrder = DB::table('v2_order')
                ->where('user_id', $userId)
                ->where('plan_id', $user->plan_id)
                ->where('status', 3)
                ->whereIn('type', [1, 2, 3])
                ->whereIn('period', $yearlyPeriods)
                ->orderBy('id', 'desc')
                ->first();
            if ($yearlyOrder) {
                $months = $periodMonths[$yearlyOrder->period] ?? 12;
                $orderExpiry = strtotime("+{$months} month", (int)$yearlyOrder->created_at);
                if ($orderExpiry > time()) {
                    $passYearlyCheck = true;
                }
            }

            // 条件2：用户剩余订阅时间 >= 365 天（兼容年费后续月付等混合续费场景）
            if (!$passYearlyCheck) {
                $remainingSeconds = $expiredAtTimestamp - time();
                if ($remainingSeconds >= 365 * 86400) {
                    $passYearlyCheck = true;
                }
            }

            if (!$passYearlyCheck) {
                abort(422, '该服务器要求年付及以上套餐才可开通（需购买年付套餐或剩余时间不少于一年）');
            }
        }

        // 调用EmbyService创建账号
        $result = $this->embyService->createEmbyUser($userId, $serverId);
    
        if ($result['success']) {
            return response([
                'data' => $result['data']
            ]);
        } else {
            abort(500, $result['message']);
        }
    }

    /**
     * 删除Emby账号
     */
    public function drop(Request $request)
    {
        $userId = $request->user['id'] ?? null;
        if (!$userId) {
            abort(500, '用户不存在');
        }
    
        $serverId = (int)$request->input('server_id');
        if (!$serverId) {
            abort(422, '请指定要删除的服务器');
        }
    
        // 频控：按服务器，删除账户每6小时仅可一次
        $recentDelete = DB::table('v2_emby_logs')
            ->where('user_id', $userId)
            ->where('emby_server_id', $serverId)
            ->where('action', 'delete')
            ->where('created_at', '>=', now()->subHours(6))
            ->first();
        if ($recentDelete) {
            abort(429, '删除账号频率限制：同一服务器每6小时仅可删除一次');
        }
    
        // 检查账号是否存在
        $account = DB::table('v2_emby_users')
            ->where('user_id', $userId)
            ->where('emby_server_id', $serverId)
            ->first();
    
        if (!$account) {
            abort(422, '账号不存在');
        }
    
        $result = $this->embyService->deleteEmbyUser($userId, $serverId);
    
        if ($result['success']) {
            return response([
                'data' => [
                    'message' => $result['message']
                ]
            ]);
        } else {
            abort(500, $result['message']);
        }
    }


    /**
     * 重置密码
     */
    public function resetPassword(Request $request)
    {
        $userId = $request->user['id'] ?? null;
        if (!$userId) {
            abort(500, '用户不存在');
        }
    
        $serverId = (int)$request->input('server_id');
        if (!$serverId) {
            abort(422, '请指定服务器');
        }
    
        // 频控：按服务器，重置密码每6小时仅可一次
        $recentReset = DB::table('v2_emby_logs')
            ->where('user_id', $userId)
            ->where('emby_server_id', $serverId)
            ->where('action', 'reset_password')
            ->where('created_at', '>=', now()->subHours(6))
            ->first();
        if ($recentReset) {
            abort(429, '重置密码频率限制：同一服务器每6小时仅可重置一次');
        }
    
        // 检查账号是否存在
        $account = DB::table('v2_emby_users')
            ->where('user_id', $userId)
            ->where('emby_server_id', $serverId)
            ->first();
    
        if (!$account) {
            abort(422, '账号不存在');
        }
    
        $result = $this->embyService->resetEmbyPassword($userId, $serverId);
    
        if ($result['success']) {
            return response([
                'data' => $result['data']
            ]);
        } else {
            abort(500, $result['message']);
        }
    }

    /**
     * 获取用户可见的 Emby 服务器在线状态
     */
    public function getServerStatus(Request $request)
    {
        $userId = $request->user['id'] ?? null;
        if (!$userId) {
            abort(401, '未登录');
        }

        $servers = DB::table('v2_emby_servers')
            ->where('status', 1)
            ->select(['id', 'name', 'url', 'api_key', 'current_users', 'max_users', 'last_check_at'])
            ->get();

        // 当前用户已有账号的服务器ID集合
        $accountServerIds = DB::table('v2_emby_users')
            ->where('user_id', $userId)
            ->pluck('emby_server_id')
            ->flip()
            ->all();

        $result = [];
        foreach ($servers as $server) {
            $online = false;
            try {
                $resp = Http::timeout(5)
                    ->withoutVerifying()
                    ->get(rtrim($server->url, '/') . '/emby/System/Ping?api_key=' . $server->api_key);
                $online = $resp->successful();
            } catch (\Exception $e) {
                // 连接失败，online 保持 false
            }

            $maxUsers    = $server->max_users ? (int)$server->max_users : null;
            $currentUsers = (int)$server->current_users;

            $result[] = [
                'id'            => $server->id,
                'name'          => $server->name,
                'url'           => $server->url,
                'status'        => $online,
                'current_users' => $currentUsers,
                'max_users'     => $maxUsers,
                'last_check_at' => $server->last_check_at ? strtotime($server->last_check_at) : null,
                'usage_rate'    => $maxUsers ? round(($currentUsers / $maxUsers) * 100, 1) : 0,
                'has_account'   => isset($accountServerIds[$server->id]),
            ];
        }

        return response(['data' => $result]);
    }

    // ============ 私有方法 ============

    /**
     * 检查是否为管理员
     */
    private function isAdmin($request)
    {
        $user = $request->user ?? [];
        
        // 强制检查：如果用户是员工，直接返回false（优先级最高）
        // 使用更宽松的比较方式，支持字符串和数字类型
        $isStaff = (isset($user['is_staff']) && (
            $user['is_staff'] === 1 || 
            $user['is_staff'] === '1' || 
            $user['is_staff'] === true
        ));
        
        if ($isStaff) {
            return false;
        }
        
        // 检查 is_admin 标志（使用更宽松的比较方式）
        $isAdminFlag = (isset($user['is_admin']) && (
            $user['is_admin'] === 1 || 
            $user['is_admin'] === '1' || 
            $user['is_admin'] === true
        ));
        
        // 检查邮箱白名单（仅当用户不是员工时）
        $emailWhitelist = config('app.admin_emails', []);
        $inWhitelist = is_array($emailWhitelist) && isset($user['email']) && in_array($user['email'], $emailWhitelist, true);
        
        // 最终结果：只有 is_admin=1 或者邮箱在白名单中（且不是员工）才返回true
        return $isAdminFlag || $inWhitelist;
    }

    /**
     * 获取所有有权限的服务器列表（包括已创建账户的服务器）
     * 关键修改：不再过滤已创建账户的服务器，添加has_account标识，隐藏IP地址
     */
    private function getAllAvailableServers($userId, $ignoreSubscription = false)
    {
        
        // 1. 获取用户信息 - 使用实际的数据库字段
        $user = DB::table('v2_user')
            ->where('id', $userId)
            ->select(['id', 'plan_id', 'expired_at', 'banned'])
            ->first();
        
        if (!$user) {
            return [];
        }

        // 2. 检查用户订阅状态（可选跳过，仅用于展示）
        if (!$ignoreSubscription) {
            if (!$this->checkUserValidSubscription($user)) {
                return [];
            }
        }

        // 3. 获取已有账号的服务器ID
        $existingServerIds = DB::table('v2_emby_users')
            ->where('user_id', $userId)
            ->pluck('emby_server_id')
            ->toArray();


        // 4. 获取所有启用的服务器（不过滤已有账户的服务器）
        $allServers = DB::table('v2_emby_servers')
            ->where('status', 1)
            ->get()
            ->toArray();


        // 5. 过滤有权限的服务器（包括已创建账户的）
        $availableServers = [];
        foreach ($allServers as $server) {
            $server = (array)$server;
            
            // 检查套餐权限
            $planIds = $server['plan_ids'] ? json_decode($server['plan_ids'], true) : [];
            if (!empty($planIds) && !in_array($user->plan_id, $planIds)) {
                continue;
            }

            // 检查是否已有账户
            $hasAccount = in_array($server['id'], $existingServerIds);

            // 对于未创建账户的服务器，检查用户数限制
            // 对于已创建账户的服务器，不检查用户数限制
            if (!$hasAccount && $server['max_users'] && $server['current_users'] >= $server['max_users']) {
                continue;
            }

            // 生成服务器描述（不显示完整URL和IP地址）
            $serverDescription = $this->generateServerDescription($server['url']);

            // 多线路与备注（容错：字段可能不存在）
            $urls = [];
            if (isset($server['urls']) && !empty($server['urls'])) {
                try {
                    $decoded = json_decode($server['urls'], true);
                    if (is_array($decoded)) {
                        // 仅保留 { name, url } 结构
                        foreach ($decoded as $line) {
                            if (isset($line['url'])) {
                                $urls[] = [
                                    'name' => isset($line['name']) ? $line['name'] : null,
                                    'url' => $line['url']
                                ];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // 忽略解析错误，保持空数组
                }
            }
            $remarks = isset($server['remarks']) ? $server['remarks'] : null;

            // 使用默认的媒体库数量和统计信息，避免实时请求
            $libraryCount = 0;
            $mediaStats = [
                'movie_count' => 0,
                'series_count' => 0,
                'episode_count' => 0,
                'song_count' => 0,
                'album_count' => 0,
                'artist_count' => 0,
                'music_video_count' => 0,
                'book_count' => 0,
                'game_count' => 0,
                'trailer_count' => 0,
                'box_set_count' => 0,
                'program_count' => 0,
                'game_system_count' => 0,
                'total_item_count' => 0,
                'is_favorite_count' => 0
            ];

            // 检查是否满足年费要求
            $requireYearly = !empty($server['require_yearly']);
            $meetsYearlyRequirement = true;
            if ($requireYearly && !$hasAccount) {
                $yearlyPeriods = ['year_price', 'two_year_price', 'three_year_price'];
                $periodMonths = ['year_price' => 12, 'two_year_price' => 24, 'three_year_price' => 36];
                $meetsYearlyRequirement = false;

                // 条件1：存在一笔年付订单且该订单计算到期时间未过期
                $yearlyOrder = DB::table('v2_order')
                    ->where('user_id', $userId)
                    ->where('plan_id', $user->plan_id)
                    ->where('status', 3)
                    ->whereIn('type', [1, 2, 3])
                    ->whereIn('period', $yearlyPeriods)
                    ->orderBy('id', 'desc')
                    ->first();
                if ($yearlyOrder) {
                    $months = $periodMonths[$yearlyOrder->period] ?? 12;
                    $orderExpiry = strtotime("+{$months} month", (int)$yearlyOrder->created_at);
                    if ($orderExpiry > time()) {
                        $meetsYearlyRequirement = true;
                    }
                }

                // 条件2：用户剩余订阅时间 >= 365 天
                if (!$meetsYearlyRequirement && $user->expired_at) {
                    $remainingSeconds = (int)$user->expired_at - time();
                    if ($remainingSeconds >= 365 * 86400) {
                        $meetsYearlyRequirement = true;
                    }
                }
            }

            // 添加到服务器列表
            $availableServers[] = [
                'id' => (int)$server['id'],
                'name' => $server['name'],
                'url' => $serverDescription, // 使用描述而不是完整URL
                'description' => $serverDescription, // 添加描述字段
                'urls' => $urls, // 多线路（可为空数组）
                'remarks' => $remarks,
                'current_users' => (int)($server['current_users'] ?? 0),
                'max_users' => $server['max_users'] ? (int)$server['max_users'] : null,
                'usage_rate' => $server['max_users'] ?
                    round(($server['current_users'] / $server['max_users']) * 100, 1) : 0,
                'status' => true,
                'has_account' => $hasAccount, // 关键：标识用户是否已在此服务器创建账户
                'require_yearly' => $requireYearly, // 是否要求年费套餐
                'meets_yearly_requirement' => $meetsYearlyRequirement, // 当前用户是否满足年费要求
                'library_count' => $libraryCount, // 添加媒体库数量
                'media_statistics' => $mediaStats // 添加详细媒体统计信息
            ];
            
        }


        return $availableServers;
    }

    /**
     * 生成服务器描述（隐藏完整URL和IP地址）
     */
    private function generateServerDescription($url)
    {
        try {
            $parsedUrl = parse_url($url);
            
            if (!$parsedUrl || !isset($parsedUrl['host'])) {
                return '高速服务器';
            }

            $host = $parsedUrl['host'];
            
            // 检查是否为IP地址
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                // 如果是IP地址，不显示，改为显示通用描述
                return '高速服务器';
            }
            
            // 如果是域名，只显示主域名（不显示子域名的详细信息）
            $hostParts = explode('.', $host);
            if (count($hostParts) >= 2) {
                // 只显示主域名，隐藏具体的子域名
                $mainDomain = $hostParts[count($hostParts) - 2] . '.' . $hostParts[count($hostParts) - 1];
                
                // 添加端口信息（如果不是标准端口）
                $port = isset($parsedUrl['port']) ? $parsedUrl['port'] : null;
                if ($port && !in_array($port, [80, 443, 8096])) {
                    return $mainDomain . ':' . $port;
                }
                
                return $mainDomain;
            }
            
            return '高速服务器';
            
        } catch (\Exception $e) {
            \Log::warning('解析服务器URL失败', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return '高速服务器';
        }
    }

    /**
     * 检查用户订阅是否有效（兼容V2Board数据库结构）
     */
    private function checkUserValidSubscription($user)
    {
        // 检查是否有套餐
        if (!$user->plan_id) {
            \Log::info('用户没有套餐', ['user_id' => $user->id]);
            return false;
        }

        // 检查是否有过期时间 - V2Board的expired_at是bigint类型的时间戳
        if (!$user->expired_at) {
            \Log::info('用户没有过期时间', ['user_id' => $user->id]);
            return false;
        }

        // V2Board的expired_at字段已经是时间戳格式
        $expiredAtTimestamp = (int)$user->expired_at;

        // 检查是否过期
        $currentTime = time();
        $isExpired = $expiredAtTimestamp <= $currentTime;
        
        if ($isExpired) {
            \Log::info('用户订阅已过期', [
                'user_id' => $user->id,
                'expired_at' => $expiredAtTimestamp,
                'current_time' => $currentTime,
                'expired_at_formatted' => date('Y-m-d H:i:s', $expiredAtTimestamp),
                'current_time_formatted' => date('Y-m-d H:i:s', $currentTime)
            ]);
            return false;
        }

        // 检查用户状态 - V2Board使用banned字段，0表示正常，1表示被禁用
        if ($user->banned == 1) {
            \Log::info('用户被禁用', [
                'user_id' => $user->id,
                'banned' => $user->banned
            ]);
            return false;
        }

        \Log::info('用户订阅验证通过', [
            'user_id' => $user->id,
            'plan_id' => $user->plan_id,
            'expired_at' => date('Y-m-d H:i:s', $expiredAtTimestamp),
            'banned' => $user->banned
        ]);

        return true;
    }

    // ============ 管理员功能（保持原有功能不变）============

    /**
     * 获取统计数据（管理员）
     */
    public function getStatistics(Request $request)
    {
        if (!$this->isAdmin($request)) {
            abort(403, '权限不足');
        }

        $statistics = [
            'total_servers' => DB::table('v2_emby_servers')->count(),
            'active_servers' => DB::table('v2_emby_servers')->where('status', 1)->count(),
            'total_users' => DB::table('v2_emby_users')->count(),
            'active_users' => DB::table('v2_emby_users')->where('status', 1)->count(),
            'expired_users' => DB::table('v2_emby_users')->where('expired_at', '<', now())->count(),
        ];

        // 最近7天的活动统计
        $recentActivity = DB::table('v2_emby_logs')
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();

        return response([
            'data' => [
                'statistics' => $statistics,
                'recent_activity' => $recentActivity
            ]
        ]);
    }

    /**
     * 获取服务器列表（管理员）- 修复响应格式
     */
    public function getServers(Request $request)
    {
        if (!$this->isAdmin($request)) {
            abort(403, '权限不足');
        }

        $current = (int)$request->input('current', 1);
        $pageSize = (int)$request->input('pageSize', 15);
        $search = $request->input('search', '');

        $query = DB::table('v2_emby_servers');
        
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('url', 'like', "%{$search}%");
            });
        }

        $total = $query->count();
        
        $servers = $query->orderBy('created_at', 'desc')
            ->offset(($current - 1) * $pageSize)
            ->limit($pageSize)
            ->get()
            ->toArray();

        // 处理服务器数据
        foreach ($servers as &$server) {
            $server = (array)$server;
            $server['plan_ids'] = $server['plan_ids'] ? json_decode($server['plan_ids'], true) : [];
            $server['created_at'] = $server['created_at'] ? strtotime($server['created_at']) : null;
            $server['updated_at'] = $server['updated_at'] ? strtotime($server['updated_at']) : null;
            $server['last_check_at'] = $server['last_check_at'] ? strtotime($server['last_check_at']) : null;
            $server['urls'] = isset($server['urls']) && $server['urls'] ? (json_decode($server['urls'], true) ?: []) : [];
            $server['remarks'] = isset($server['remarks']) ? $server['remarks'] : null;
            $server['user_agent'] = isset($server['user_agent']) ? $server['user_agent'] : null;  // ← 添加这行
        }

        // 获取套餐列表（包含未上架的套餐）
        $plans = DB::table('v2_plan')
            ->select(['id', 'name'])
            ->orderBy('id', 'asc')
            ->get()
            ->toArray();

        // 修复：返回正确的数据结构，与前端adapter兼容
        return response([
            'data' => [
                'servers' => $servers,
                'total' => $total,
                'current_page' => $current,
                'plans' => $plans
            ]
        ]);
    }

    /**
     * 保存服务器（管理员）：新增或更新
     */
    public function saveServer(Request $request)
    {
        if (!$this->isAdmin($request)) {
            abort(403, '权限不足');
        }
    
        $id = (int)$request->input('id', 0);
        $name = trim((string)$request->input('name'));
        $url = trim((string)$request->input('url'));
        $apiKey = $request->input('api_key');
        $planIds = $request->input('plan_ids', []);
        $maxUsers = $request->input('max_users');
        $status = $request->input('status', 1);
        $remarks = $request->input('remarks');
        $requireYearly = $request->input('require_yearly', 0);
        $urls = $request->input('urls', []);
        $userAgent = $request->input('user_agent');
        $clientName = $request->input('client_name');
    
        if ($name === '' || $url === '') {
            abort(422, '名称与地址不能为空');
        }
        if ($id === 0 && (!$apiKey || trim((string)$apiKey) === '')) {
            abort(422, '新增服务器需要提供 API 密钥');
        }
        if ($requireYearly && (!is_array($planIds) || empty($planIds))) {
            abort(422, '启用年费套餐要求时，必须选择允许的套餐');
        }
    
        // 规范化字段
        $planIdsJson = is_array($planIds) ? json_encode(array_values($planIds)) : null;
        $urlsJson = is_array($urls) ? json_encode(array_values(array_map(function ($it) {
            return [
                'name' => isset($it['name']) ? (string)$it['name'] : null,
                'url' => isset($it['url']) ? (string)$it['url'] : ''
            ];
        }, $urls))) : null;
    
        if ($id > 0) {
            // 更新
            $server = DB::table('v2_emby_servers')->where('id', $id)->first();
            if (!$server) abort(404, '服务器不存在');
    
            $data = [
                'name' => $name,
                'url' => $url,
                'plan_ids' => $planIdsJson,
                'require_yearly' => $requireYearly ? 1 : 0,
                'max_users' => $maxUsers !== null && $maxUsers !== '' ? (int)$maxUsers : null,
                'status' => $status ? 1 : 0,
                'remarks' => $remarks,
                'urls' => $urlsJson,
                'user_agent' => $userAgent,
                'client_name' => $clientName,
                'updated_at' => now(),
            ];
            if ($apiKey && trim((string)$apiKey) !== '') {
                $data['api_key'] = $apiKey;
            }
    
            DB::table('v2_emby_servers')->where('id', $id)->update($data);
    
            return response(['data' => ['message' => '服务器更新成功']]);
    
        } else {
            // 新增
            DB::table('v2_emby_servers')->insert([
                'name' => $name,
                'url' => $url,
                'api_key' => (string)$apiKey,
                'plan_ids' => $planIdsJson,
                'require_yearly' => $requireYearly ? 1 : 0,
                'max_users' => $maxUsers !== null && $maxUsers !== '' ? (int)$maxUsers : null,
                'status' => $status ? 1 : 0,
                'remarks' => $remarks,
                'urls' => $urlsJson,
                'user_agent' => $userAgent,
                'client_name' => $clientName,
                'current_users' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    
            return response(['data' => ['message' => '服务器创建成功']]);
        }
    }

    /**
     * 删除服务器（管理员）
     */
    public function dropServer(Request $request)
    {
        if (!$this->isAdmin($request)) {
            abort(403, '权限不足');
        }
        $id = (int)$request->input('id');
        if (!$id) abort(422, '服务器ID不能为空');

        $deleted = DB::table('v2_emby_servers')->where('id', $id)->delete();
        if ($deleted === 0) abort(404, '服务器不存在');

        // 清理该服务器下所有关联的 Emby 用户记录
        DB::table('v2_emby_users')->where('emby_server_id', $id)->delete();

        return response(['data' => ['message' => '服务器删除成功']]);
    }

    /**
     * 测试服务器（管理员）
     */
    public function testServer(Request $request)
    {
        if (!$this->isAdmin($request)) {
            abort(403, '权限不足');
        }
        $id = (int)$request->input('id');
        if (!$id) abort(422, '服务器ID不能为空');

        $server = DB::table('v2_emby_servers')->where('id', $id)->first();
        if (!$server) abort(404, '服务器不存在');

        $baseUrl = rtrim($server->url, '/');
        $apiKey = $server->api_key;
        try {
            $resp = Http::timeout(8)
                ->withoutVerifying()
                ->get($baseUrl . '/emby/System/Info?api_key=' . $apiKey);

            DB::table('v2_emby_servers')->where('id', $id)->update(['last_check_at' => now()]);

            if ($resp->successful()) {
                return response(['data' => ['message' => '连接成功']]);
            }
            abort(500, '连接失败：HTTP ' . $resp->status());
        } catch (\Exception $e) {
            abort(500, '连接失败：' . $e->getMessage());
        }
    }

    /**
     * 获取用户列表（管理员）- 修复响应格式
     */
    public function getUsers(Request $request)
    {
        if (!$this->isAdmin($request)) {
            abort(403, '权限不足');
        }

        $current = (int)$request->input('current', 1);
        $pageSize = (int)$request->input('pageSize', 15);
        $search = $request->input('search', '');
        $serverId = $request->input('server_id');
        $status = $request->input('status');

        $query = DB::table('v2_emby_users as eu')
            ->leftJoin('v2_user as u', 'eu.user_id', '=', 'u.id')
            ->leftJoin('v2_emby_servers as es', 'eu.emby_server_id', '=', 'es.id');

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('u.email', 'like', "%{$search}%")
                  ->orWhere('eu.username', 'like', "%{$search}%");
            });
        }

        if ($serverId) {
            $query->where('eu.emby_server_id', $serverId);
        }

        $isExpired = $request->input('is_expired');

        if ($isExpired !== null && $isExpired !== '') {
            if ($isExpired) {
                $query->whereNotNull('eu.expired_at')
                      ->whereRaw('eu.expired_at < NOW()');
            } else {
                $query->where(function($q) {
                    $q->whereNull('eu.expired_at')
                      ->orWhereRaw('eu.expired_at >= NOW()');
                });
            }
        }

        if ($status !== null && $status !== '') {
            $query->where('eu.status', $status);
        }

        $total = $query->count();

        $users = $query->select([
                'eu.id',
                'eu.user_id',
                'eu.username',
                'eu.expired_at',
                'eu.status',
                'eu.created_at',
                'u.email as user_email',
                'es.name as server_name'
            ])
            ->orderBy('eu.created_at', 'desc')
            ->offset(($current - 1) * $pageSize)
            ->limit($pageSize)
            ->get()
            ->toArray();

        // 处理用户数据
        foreach ($users as &$user) {
            $user = (array)$user;
            $user['user_email'] = $user['user_email'] ?: 'N/A';
            $user['expired_at'] = $user['expired_at'] ? strtotime($user['expired_at']) : null;
            $user['created_at'] = $user['created_at'] ? strtotime($user['created_at']) : null;
            $user['is_expired'] = $user['expired_at'] ? time() > $user['expired_at'] : false;
            $user['is_active'] = $user['status'] && !$user['is_expired'];
        }

        // 获取服务器列表
        $servers = DB::table('v2_emby_servers')
            ->where('status', 1)
            ->select(['id', 'name'])
            ->get()
            ->toArray();

        // 修复：返回正确的数据结构，与前端adapter兼容
        return response([
            'data' => [
                'users' => $users,
                'total' => $total,
                'current_page' => $current,
                'servers' => $servers
            ]
        ]);
    }

    /**
     * 删除用户（管理员）
     */
    public function dropUser(Request $request)
    {
        if (!$this->isAdmin($request)) {
            abort(403, '权限不足');
        }

        $id = (int)$request->input('id');
        if (!$id) {
            abort(422, '用户ID不能为空');
        }

        $embyUser = DB::table('v2_emby_users')->where('id', $id)->first();
        if (!$embyUser) {
            abort(422, '用户不存在');
        }

        $result = $this->embyService->deleteEmbyUser($embyUser->user_id, $embyUser->emby_server_id);

        if ($result['success']) {
            return response([
                'data' => [
                    'message' => '用户删除成功'
                ]
            ]);
        } else {
            abort(500, $result['message']);
        }
    }

    /**
     * 同步用户（管理员）
     */
    public function syncUsers(Request $request)
    {
        if (!$this->isAdmin($request)) {
            abort(403, '权限不足');
        }

        $serverId = $request->input('server_id');
        
        if ($serverId) {
            // 同步指定服务器的用户
            $users = DB::table('v2_user as u')
                ->join('v2_emby_users as eu', 'u.id', '=', 'eu.user_id')
                ->where('eu.emby_server_id', $serverId)
                ->whereNotNull('u.plan_id')
                ->whereNotNull('u.expired_at')
                ->pluck('u.id')
                ->toArray();
        } else {
            // 同步所有用户
            $users = DB::table('v2_user as u')
                ->join('v2_emby_users as eu', 'u.id', '=', 'eu.user_id')
                ->whereNotNull('u.plan_id')
                ->whereNotNull('u.expired_at')
                ->pluck('u.id')
                ->unique()
                ->toArray();
        }

        $result = $this->embyService->batchSyncUserExpiration($users);

        return response([
            'data' => [
                'message' => "同步完成！成功: {$result['success']}, 失败: {$result['failed']}, 总计: {$result['total']}"
            ]
        ]);
    }

    // （已移除）临时调试方法 debugPassword
    /**
     * 获取操作日志（管理员）- 修复响应格式
     */
    public function getLogs(Request $request)
    {
        if (!$this->isAdmin($request)) {
            abort(403, '权限不足');
        }

        $current = (int)$request->input('current', 1);
        $pageSize = (int)$request->input('pageSize', 20);
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $action = $request->input('action');
        $userId = $request->input('user_id');

        $query = DB::table('v2_emby_logs as el')
            ->leftJoin('v2_user as u', 'el.user_id', '=', 'u.id')
            ->leftJoin('v2_emby_servers as es', 'el.emby_server_id', '=', 'es.id');

        if ($startDate) {
            $query->where('el.created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('el.created_at', '<=', $endDate . ' 23:59:59');
        }
        if ($action) {
            $query->where('el.action', $action);
        }
        if ($userId) {
            $query->where('el.user_id', $userId);
        }

        $total = $query->count();

        $logs = $query->select([
                'el.id',
                'el.action',
                'el.message',
                'el.ip',
                'el.created_at',
                'u.email as user_email',
                'es.name as server_name'
            ])
            ->orderBy('el.created_at', 'desc')
            ->offset(($current - 1) * $pageSize)
            ->limit($pageSize)
            ->get()
            ->toArray();

        // 处理日志数据
        foreach ($logs as &$log) {
            $log = (array)$log;
            $log['user_email'] = $log['user_email'] ?: 'N/A';
            $log['server_name'] = $log['server_name'] ?: 'N/A';
            $log['created_at'] = $log['created_at'] ? strtotime($log['created_at']) : null;
        }

        // 修复：返回正确的数据结构，与前端adapter兼容
        return response([
            'data' => [
                'logs' => $logs,
                'total' => $total,
                'current_page' => $current
            ]
        ]);
    }

    /**
     * 清理日志（管理员）
     */
    public function clearLogs(Request $request)
    {
        if (!$this->isAdmin($request)) {
            abort(403, '权限不足');
        }

        $days = (int)$request->input('days', 30);
        $threshold = now()->subDays(max($days, 0));

        $deleted = DB::table('v2_emby_logs')
            ->where('created_at', '<', $threshold)
            ->delete();

        return response([
            'data' => [
                'message' => "已清理 {$deleted} 条日志"
            ]
        ]);
    }

    /**
     * 导出日志（管理员）- CSV
     */
    public function exportLogs(Request $request)
    {
        if (!$this->isAdmin($request)) {
            abort(403, '权限不足');
        }

        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $action = $request->input('action');
        $userId = $request->input('user_id');

        $query = DB::table('v2_emby_logs as el')
            ->leftJoin('v2_user as u', 'el.user_id', '=', 'u.id')
            ->leftJoin('v2_emby_servers as es', 'el.emby_server_id', '=', 'es.id');

        if ($startDate) {
            $query->where('el.created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('el.created_at', '<=', $endDate . ' 23:59:59');
        }
        if ($action) {
            $query->where('el.action', $action);
        }
        if ($userId) {
            $query->where('el.user_id', $userId);
        }

        $logs = $query->select([
                'el.id',
                'el.action',
                'el.message',
                'el.ip',
                'el.created_at',
                'u.email as user_email',
                'es.name as server_name'
            ])
            ->orderBy('el.created_at', 'desc')
            ->get();

        // 生成 CSV
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="emby_logs.csv"'
        ];

        $callback = function () use ($logs) {
            $out = fopen('php://output', 'w');
            // BOM 以便 Excel 正确识别 UTF-8
            fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, ['ID', '操作', '信息', 'IP', '时间', '用户邮箱', '服务器']);
            foreach ($logs as $log) {
                fputcsv($out, [
                    $log->id,
                    $log->action,
                    $log->message,
                    $log->ip,
                    $log->created_at,
                    $log->user_email ?? 'N/A',
                    $log->server_name ?? 'N/A'
                ]);
            }
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * 诊断：逐项尝试更新 Emby 用户状态（管理员）
     * 入参：server_id, emby_user_id, enabled(1/0)
     */
    public function diagnose(Request $request)
    {
        // 放宽权限：普通用户也可调用诊断，仅返回尝试信息，不做任何修改

        $serverId = (int)$request->input('server_id');
        $embyUserId = (string)$request->input('emby_user_id');
        $enabled = (bool)$request->input('enabled', 1);

        if (!$serverId || !$embyUserId) {
            abort(422, 'server_id 与 emby_user_id 不能为空');
        }

        $result = $this->embyService->diagnoseUserStatusUpdate($serverId, $embyUserId, $enabled);

        return response(['data' => $result]);
    }

    /**
     * 批量清理过期账号（管理员）
     * 参数：days 可选，表示过期超过 N 天的账号才清理；默认立即清理已过期账号
     * 返回：{ message, total, success, failed }
     */
    public function batchCleanup(Request $request)
    {
        if (!$this->isAdmin($request)) {
            abort(403, '权限不足');
        }

        $days = (int)$request->input('days', 0);
        $threshold = $days > 0 ? now()->subDays($days) : now();

        // 同时校验 v2_user 的实际订阅状态，避免误删已续费用户
        $expiredUsers = DB::table('v2_emby_users as eu')
            ->join('v2_user as u', 'eu.user_id', '=', 'u.id')
            ->whereNotNull('eu.expired_at')
            ->where('eu.expired_at', '<', $threshold)
            ->where(function ($q) {
                $q->whereNull('u.plan_id')
                  ->orWhere('u.expired_at', '<', time())
                  ->orWhere('u.banned', 1);
            })
            ->select(['eu.id', 'eu.user_id', 'eu.emby_server_id'])
            ->get();

        $total = $expiredUsers->count();
        $success = 0;
        $failed = 0;

        foreach ($expiredUsers as $eu) {
            try {
                $res = $this->embyService->deleteEmbyUser($eu->user_id, $eu->emby_server_id);
                if (!empty($res['success'])) {
                    $success++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
            }
        }

        return response([
            'data' => [
                'message' => "清理完成：成功 {$success}，失败 {$failed}，总计 {$total}",
                'total' => $total,
                'success' => $success,
                'failed' => $failed
            ]
        ]);
    }

    /**
     * 批量同步（管理员）
     * 参数：server_id 可选；user_ids 可选
     */
    public function batchSync(Request $request)
    {
        if (!$this->isAdmin($request)) {
            abort(403, '权限不足');
        }

        $serverId = $request->input('server_id');
        $userIds = $request->input('user_ids');

        $ids = null;
        if (is_array($userIds) && count($userIds) > 0) {
            $ids = array_values(array_unique(array_map('intval', $userIds)));
        } elseif ($serverId) {
            // 按服务器筛选用户ID
            $ids = DB::table('v2_user as u')
                ->join('v2_emby_users as eu', 'u.id', '=', 'eu.user_id')
                ->where('eu.emby_server_id', (int)$serverId)
                ->whereNotNull('u.plan_id')
                ->whereNotNull('u.expired_at')
                ->pluck('u.id')
                ->unique()
                ->toArray();
        }

        $result = $this->embyService->batchSyncUserExpiration($ids);

        return response([
            'data' => [
                'message' => "同步完成：成功 {$result['success']}，失败 {$result['failed']}，总计 {$result['total']}",
                'total' => $result['total'],
                'success' => $result['success'],
                'failed' => $result['failed']
            ]
        ]);
    }

}
