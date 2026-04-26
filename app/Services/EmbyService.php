<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class EmbyService
{
    private $timeout;

    public function __construct()
    {
        $this->timeout = config('emby.api.timeout', 30);
    }
    
    /**
     * 获取真实客户端IP（兼容多层代理）
     */
    private function getRealIp()
    {
        foreach ([
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
    
    /**
    * 诊断：逐项尝试更新用户状态并返回详细尝试结果（不抛异常）
    */
    public function diagnoseUserStatusUpdate(int $serverId, string $embyUserId, bool $enabled): array
    {
        $server = DB::table('v2_emby_servers')->where('id', $serverId)->first();
        if (!$server) {
            return ['success' => false, 'error' => 'server_not_found'];
        }
    
        $baseUrl = rtrim($server->url, '/');
        $apiKey = $server->api_key;
        $attempts = [];
    
        // ← 构建包含自定义 User-Agent 的基础 headers
        $baseHeaders = [];
        if (!empty($server->user_agent)) {
            $baseHeaders['User-Agent'] = $server->user_agent;
        }
    
        $endpoints = [
            '/Users/' . $embyUserId . '/Policy',
            '/emby/Users/' . $embyUserId . '/Policy'
        ];
        $methods = ['POST', 'PUT'];
    
        foreach ($endpoints as $ep) {
            $url = $baseUrl . $ep;
            try {
                // 方式1: Query 参数 + 自定义UA
                $r = Http::timeout($this->timeout)
                    ->withoutVerifying()
                    ->withHeaders($baseHeaders)  // ← 添加
                    ->get($url . '?api_key=' . $apiKey);
                
                $attempts[] = ['step' => 'get_policy_query', 'endpoint' => $ep, 'status' => $r->status(), 'ok' => $r->successful(), 'preview' => substr($r->body(), 0, 120)];
                
                if (!$r->successful()) {
                    // 方式2: Header 认证 + 自定义UA
                    $authHeaders = array_merge($baseHeaders, [  // ← 合并
                        'X-Emby-Token' => $apiKey,
                        'Authorization' => 'MediaBrowser Token="' . $apiKey . '"',
                        'Accept' => 'application/json'
                    ]);
                    
                    $r = Http::timeout($this->timeout)
                        ->withoutVerifying()
                        ->withHeaders($authHeaders)  // ← 使用合并后的
                        ->get($url);
                    
                    $attempts[] = ['step' => 'get_policy_header', 'endpoint' => $ep, 'status' => $r->status(), 'ok' => $r->successful(), 'preview' => substr($r->body(), 0, 120)];
                }
    
                $policy = $r->successful() ? ($r->json() ?: []) : [];
                if (!is_array($policy)) $policy = [];
                $policy['IsDisabled'] = !$enabled;
                $policy['Id'] = (string)$embyUserId;
    
                foreach ($methods as $m) {
                    // Query 方式 + 自定义UA
                    $r2 = Http::timeout($this->timeout)
                        ->withoutVerifying()
                        ->asJson()
                        ->withHeaders($baseHeaders)  // ← 添加
                        ->{strtolower($m)}($url . '?api_key=' . $apiKey, $policy);
                    
                    $attempts[] = ['step' => 'set_policy_query_' . strtolower($m), 'endpoint' => $ep, 'status' => $r2->status(), 'ok' => $r2->successful(), 'preview' => substr($r2->body(), 0, 120)];
                    if ($r2->successful()) return ['success' => true, 'attempts' => $attempts];
    
                    // Header 方式 + 自定义UA
                    $authHeaders = array_merge($baseHeaders, [  // ← 合并
                        'X-Emby-Token' => $apiKey,
                        'Authorization' => 'MediaBrowser Token="' . $apiKey . '"',
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json; charset=UTF-8'
                    ]);
                    
                    $r3 = Http::timeout($this->timeout)
                        ->withoutVerifying()
                        ->asJson()
                        ->withHeaders($authHeaders)  // ← 使用合并后的
                        ->{strtolower($m)}($url, $policy);
                    
                    $attempts[] = ['step' => 'set_policy_header_' . strtolower($m), 'endpoint' => $ep, 'status' => $r3->status(), 'ok' => $r3->successful(), 'preview' => substr($r3->body(), 0, 120)];
                    if ($r3->successful()) return ['success' => true, 'attempts' => $attempts];
                }
            } catch (Exception $e) {
                $attempts[] = ['step' => 'exception', 'endpoint' => $ep, 'ok' => false, 'error' => $e->getMessage()];
            }
        }
    
        $toggles = $enabled
            ? ['/Users/' . $embyUserId . '/Enable', '/emby/Users/' . $embyUserId . '/Enable']
            : ['/Users/' . $embyUserId . '/Disable', '/emby/Users/' . $embyUserId . '/Disable'];
    
        foreach ($toggles as $ep) {
            $url = $baseUrl . $ep;
            try {
                // Query 方式 + 自定义UA
                $r = Http::timeout($this->timeout)
                    ->withoutVerifying()
                    ->withHeaders($baseHeaders)  // ← 添加
                    ->post($url . '?api_key=' . $apiKey);
                
                $attempts[] = ['step' => 'toggle_query', 'endpoint' => $ep, 'status' => $r->status(), 'ok' => $r->successful(), 'preview' => substr($r->body(), 0, 120)];
                if ($r->successful()) return ['success' => true, 'attempts' => $attempts];
    
                // Header 方式 + 自定义UA
                $authHeaders = array_merge($baseHeaders, [  // ← 合并
                    'X-Emby-Token' => $apiKey,
                    'Authorization' => 'MediaBrowser Token="' . $apiKey . '"'
                ]);
                
                $r2 = Http::timeout($this->timeout)
                    ->withoutVerifying()
                    ->withHeaders($authHeaders)  // ← 使用合并后的
                    ->post($url);
                
                $attempts[] = ['step' => 'toggle_header', 'endpoint' => $ep, 'status' => $r2->status(), 'ok' => $r2->successful(), 'preview' => substr($r2->body(), 0, 120)];
                if ($r2->successful()) return ['success' => true, 'attempts' => $attempts];
            } catch (Exception $e) {
                $attempts[] = ['step' => 'toggle_exception', 'endpoint' => $ep, 'ok' => false, 'error' => $e->getMessage()];
            }
        }
    
        return ['success' => false, 'attempts' => $attempts];
    }

    /**
     * 创建Emby用户
     */
    public function createEmbyUser($userId, $embyServerId)
    {
        try {
            $user = DB::table('v2_user')->where('id', $userId)->select(['id','email','plan_id','expired_at','banned'])->first();
            if (!$user) throw new Exception('用户不存在');

            $embyServer = DB::table('v2_emby_servers')->where('id',$embyServerId)->first();
            if (!$embyServer) throw new Exception('Emby服务器不存在');

            if (!$this->checkUserSubscriptionFromDB($user)) throw new Exception('用户订阅已过期或无效');

            $planIds = $embyServer->plan_ids ? json_decode($embyServer->plan_ids,true):[];
            if (!empty($planIds) && !in_array($user->plan_id,$planIds)) throw new Exception('当前套餐不支持此Emby服务器');

            // 检查年费套餐要求
            if ($embyServer->require_yearly) {
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

                // 条件2：用户剩余订阅时间 >= 365 天
                if (!$passYearlyCheck) {
                    $remainingSeconds = (int)$user->expired_at - time();
                    if ($remainingSeconds >= 365 * 86400) {
                        $passYearlyCheck = true;
                    }
                }

                if (!$passYearlyCheck) {
                    throw new Exception('该服务器要求年付及以上套餐才可开通（需购买年付套餐或剩余时间不少于一年）');
                }
            }

            if ($embyServer->max_users && $embyServer->current_users >= $embyServer->max_users) throw new Exception('Emby服务器用户数已达上限');

            $existingUser = DB::table('v2_emby_users')->where('user_id',$userId)->where('emby_server_id',$embyServerId)->first();
            if ($existingUser) throw new Exception('您已经在此Emby服务器上拥有账号');

            $username = $user->email;
            $password = $this->generateRandomPassword();

            $embyUserId = $this->createUserOnEmbyServer($embyServer,$username,$password);

            if (!$embyUserId || !$username) throw new Exception('创建用户失败：无法生成唯一用户名');

            $expiredAt = $user->expired_at;

            DB::table('v2_emby_users')->insert([
                'user_id'=>$userId,
                'emby_server_id'=>$embyServerId,
                'emby_user_id'=>$embyUserId,
                'username'=>$username,
                'password'=>encrypt($password),
                'expired_at'=>date('Y-m-d H:i:s',$expiredAt),
                'status'=>1,
                'created_at'=>now(),
                'updated_at'=>now()
            ]);

            DB::table('v2_emby_servers')->where('id',$embyServerId)->increment('current_users');

            $this->createEmbyLog($userId,$embyServerId,'create',"成功创建Emby账号: {$username}");

            return ['success'=>true,'data'=>[
                'username'=>$username,
                'password'=>$password,
                'server_url'=>$embyServer->url,
                'server_name'=>$embyServer->name,
                'expired_at'=>$expiredAt
            ]];

        } catch (Exception $e) {
            $this->createEmbyLog($userId ?? 0, $embyServerId ?? 0,'create_failed',"创建Emby账号失败: ".$e->getMessage());
            return ['success'=>false,'message'=>$e->getMessage()];
        }
    }

    /**
     * 删除Emby用户
     */
    public function deleteEmbyUser($userId,$embyServerId)
    {
        try {
            $embyUser = DB::table('v2_emby_users')->where('user_id',$userId)->where('emby_server_id',$embyServerId)->first();
            if (!$embyUser) throw new Exception('Emby用户不存在');

            $embyServer = DB::table('v2_emby_servers')->where('id',$embyServerId)->first();
            if (!$embyServer) throw new Exception('Emby服务器不存在');

            $this->deleteUserFromEmbyServer($embyServer,$embyUser->emby_user_id);

            DB::table('v2_emby_users')->where('user_id',$userId)->where('emby_server_id',$embyServerId)->delete();

            DB::table('v2_emby_servers')->where('id',$embyServerId)->where('current_users','>',0)->decrement('current_users');

            $this->createEmbyLog($userId,$embyServerId,'delete',"成功删除Emby账号: {$embyUser->username}");

            return ['success'=>true,'message'=>'账号删除成功'];

        } catch (Exception $e) {
            $this->createEmbyLog($userId,$embyServerId,'delete_failed',"删除Emby账号失败: ".$e->getMessage());
            return ['success'=>false,'message'=>$e->getMessage()];
        }
    }

    /**
     * 重置Emby用户密码
     */
    public function resetEmbyPassword($userId,$embyServerId)
    {
        try {
            $embyUser = DB::table('v2_emby_users')->where('user_id',$userId)->where('emby_server_id',$embyServerId)->first();
            if(!$embyUser) throw new Exception('Emby用户不存在');

            $embyServer = DB::table('v2_emby_servers')->where('id',$embyServerId)->first();
            if(!$embyServer) throw new Exception('Emby服务器不存在');

            $newPassword = $this->generateRandomPassword();
            $this->updateUserPasswordOnEmbyServer($embyServer,$embyUser->emby_user_id,$newPassword);

            DB::table('v2_emby_users')->where('user_id',$userId)->where('emby_server_id',$embyServerId)->update([
                'password'=>encrypt($newPassword),
                'updated_at'=>now()
            ]);

            $this->createEmbyLog($userId,$embyServerId,'reset_password',"成功重置Emby密码: {$embyUser->username}");

            return ['success'=>true,'data'=>[
                'password'=>$newPassword,
                'username'=>$embyUser->username,
                'server_name'=>$embyServer->name
            ]];

        } catch (Exception $e) {
            $this->createEmbyLog($userId,$embyServerId,'reset_password_failed',"重置Emby密码失败: ".$e->getMessage());
            return ['success'=>false,'message'=>$e->getMessage()];
        }
    }

    /**
     * 批量同步用户到期时间
     */
    public function batchSyncUserExpiration($userIds = null)
    {
        @set_time_limit(0);

        // 用 pluck+unique 避免 JOIN 导致同一用户重复出现
        $query = DB::table('v2_user as u')
            ->join('v2_emby_users as eu', 'u.id', '=', 'eu.user_id')
            ->whereNotNull('u.plan_id')
            ->whereNotNull('u.expired_at');

        if ($userIds) {
            $query->whereIn('u.id', $userIds);
        }

        $uniqueIds = $query->pluck('u.id')->unique()->values();

        // 从 v2_user 直接取数据，避免 JOIN 带来的重复行
        $users = DB::table('v2_user')
            ->whereIn('id', $uniqueIds)
            ->whereNotNull('plan_id')
            ->whereNotNull('expired_at')
            ->select(['id', 'expired_at', 'banned', 'plan_id'])
            ->get();

        $results = [
            'total' => $users->count(),
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($users as $user) {
            try {
                $this->syncUserExpiration($user->id, false);
                $results['success']++;
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "User {$user->id}: " . $e->getMessage();

                Log::error("同步用户 {$user->id} Emby状态失败", [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        if ($results['total'] > 0) {
            $message = "批次同步完成: 成功 {$results['success']} 个，失败 {$results['failed']} 个";
            if ($results['failed'] > 0) {
                $message .= "。失败用户: " . implode(', ', array_slice($results['errors'], 0, 5));
                if (count($results['errors']) > 5) {
                    $message .= " 等" . count($results['errors']) . "个";
                }
            }

            $this->createEmbyLog(0, 0, 'batch_sync', $message);
        }

        return $results;
    }

    /**
     * 同步用户到期时间
     */
    public function syncUserExpiration($userId, $logIndividual = true)
    {
        $user = DB::table('v2_user')
            ->where('id', $userId)
            ->select(['id', 'plan_id', 'expired_at', 'banned'])
            ->first();

        if (!$user) {
            throw new Exception('用户不存在');
        }

        $userTs      = (int)$user->expired_at;
        $shouldEnable = $this->checkUserSubscriptionFromDB($user);

        $embyUsers = DB::table('v2_emby_users as eu')
            ->join('v2_emby_servers as es', 'eu.emby_server_id', '=', 'es.id')
            ->where('eu.user_id', $userId)
            ->select(['eu.*', 'es.url', 'es.api_key', 'es.user_agent', 'es.client_name'])
            ->get();

        foreach ($embyUsers as $embyUser) {
            try {
                $embyTs        = $embyUser->expired_at ? strtotime($embyUser->expired_at) : 0;
                $expiredChanged = $embyTs !== $userTs;
                $statusChanged  = (int)$embyUser->status !== (int)$shouldEnable;

                // 没有任何变化，跳过（避免不必要的 API 调用）
                if (!$expiredChanged && !$statusChanged) {
                    continue;
                }

                // 状态需要变化时才调用 Emby API（最耗时操作）
                if ($statusChanged) {
                    $this->updateUserStatusOnEmbyServer(
                        $embyUser,
                        $embyUser->emby_user_id,
                        $shouldEnable
                    );
                }

                // 更新本地 DB
                $updateData = ['updated_at' => now()];
                if ($expiredChanged) {
                    $updateData['expired_at'] = $userTs > 0 ? date('Y-m-d H:i:s', $userTs) : null;
                }
                if ($statusChanged) {
                    $updateData['status'] = $shouldEnable ? 1 : 0;
                }
                DB::table('v2_emby_users')->where('id', $embyUser->id)->update($updateData);

                if ($logIndividual) {
                    $this->createEmbyLog(
                        $userId,
                        $embyUser->emby_server_id,
                        'sync',
                        "同步用户状态成功: {$embyUser->username}"
                    );
                }

            } catch (Exception $e) {
                $this->createEmbyLog(
                    $userId,
                    $embyUser->emby_server_id,
                    'sync_failed',
                    "同步用户状态失败: " . $e->getMessage()
                );
            }
        }
    }

    /**
     * 生成随机密码（8位字母数字组合）
     */
    private function generateRandomPassword()
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $password = '';
        
        for ($i = 0; $i < 8; $i++) {
            $password .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        return $password;
    }

    /**
     * 检查用户订阅状态（兼容V2Board数据库结构）
     */
    private function checkUserSubscriptionFromDB($user)
    {
        if (!$user->plan_id || !$user->expired_at) {
            return false;
        }
    
        // V2Board的expired_at字段已经是时间戳格式
        $expiredAt = (int)$user->expired_at;
        
        return $expiredAt && 
               $expiredAt > time() && 
               $user->banned == 0; // V2Board使用banned字段，0表示正常
    }

    /**
     * 创建日志（已改为真实IP）
     */
    private function createEmbyLog($userId, $embyServerId, $action, $message = null, $ip = null)
    {
        DB::table('v2_emby_logs')->insert([
            'user_id' => $userId,
            'emby_server_id' => $embyServerId,
            'action' => $action,
            'message' => $message,
            'ip' => $ip ?: $this->getRealIp(),
            'created_at' => now()
        ]);
    }

    /**
     * 更新Emby服务器用户状态
     */
    private function updateUserStatusOnEmbyServer($embyServer, $embyUserId, $enabled)
    {
        try {
            $baseUrl = rtrim($embyServer->url, '/');
            $apiKey = $embyServer->api_key;
    
            // ← 构建基础 headers（包含自定义UA）
            $baseHeaders = ['Accept' => 'application/json'];
            if (!empty($embyServer->user_agent)) {
                $baseHeaders['User-Agent'] = $embyServer->user_agent;
            }
    
            $endpoints = [
                '/Users/' . $embyUserId . '/Policy',
                '/emby/Users/' . $embyUserId . '/Policy'
            ];
    
            $methods = ['POST', 'PUT'];
    
            foreach ($endpoints as $endpoint) {
                $url = $baseUrl . $endpoint;
    
                try {
                    // 1) 取策略（Query 方式） + 自定义UA
                    $current = Http::timeout($this->timeout)
                        ->withoutVerifying()
                        ->withHeaders($baseHeaders)  // ← 添加
                        ->get($url . '?api_key=' . $apiKey);
    
                    if (!$current->successful()) {
                        // 2) 取策略（Header 方式） + 自定义UA
                        $authHeaders = array_merge($baseHeaders, [  // ← 合并
                            'X-Emby-Token' => $apiKey,
                            'Authorization' => 'MediaBrowser Token="' . $apiKey . '"'
                        ]);
                        
                        $current = Http::timeout($this->timeout)
                            ->withoutVerifying()
                            ->withHeaders($authHeaders)  // ← 使用合并后的
                            ->get($url);
                    }

                    $policy = $current->successful() ? $current->json() : [];
                    if (!is_array($policy)) $policy = [];
                    $policy['IsDisabled'] = !$enabled;
                    $policy['Id'] = (string)$embyUserId;
    
                    $resp = null;
                    foreach ($methods as $method) {
                        // 3) 更新策略（Query 方式） + 自定义UA
                        $queryHeaders = array_merge($baseHeaders, [
                            'Content-Type' => 'application/json; charset=UTF-8'
                        ]);
                        
                        $resp = Http::timeout($this->timeout)
                            ->withoutVerifying()
                            ->asJson()
                            ->withHeaders($queryHeaders)  // ← 使用合并后的
                            ->{strtolower($method)}($url . '?api_key=' . $apiKey, $policy);
    
                        if ($resp->successful()) break;
    
                        // 4) 更新策略（Header 方式） + 自定义UA
                        $authHeaders = array_merge($baseHeaders, [  // ← 合并
                            'X-Emby-Token' => $apiKey,
                            'Authorization' => 'MediaBrowser Token="' . $apiKey . '"',
                            'Content-Type' => 'application/json; charset=UTF-8'
                        ]);
                        
                        $resp = Http::timeout($this->timeout)
                            ->withoutVerifying()
                            ->asJson()
                            ->withHeaders($authHeaders)  // ← 使用合并后的
                            ->{strtolower($method)}($url, $policy);
    
                        if ($resp->successful()) break;
                    }
    
                    if ($resp->successful() || in_array($resp->status(), [200, 204])) {
                        return;
                    }
    
                } catch (Exception $inner) {
                    continue;
                }
            }
    
            // 尝试备用接口
            $toggleEndpoints = $enabled
                ? ['/Users/' . $embyUserId . '/Enable', '/emby/Users/' . $embyUserId . '/Enable']
                : ['/Users/' . $embyUserId . '/Disable', '/emby/Users/' . $embyUserId . '/Disable'];
    
            foreach ($toggleEndpoints as $tEndpoint) {
                $tUrl = $baseUrl . $tEndpoint;
                try {
                    // Query 方式 + 自定义UA
                    $resp = Http::timeout($this->timeout)
                        ->withoutVerifying()
                        ->withHeaders($baseHeaders)  // ← 添加
                        ->post($tUrl . '?api_key=' . $apiKey);
                    
                    if ($resp->successful() || in_array($resp->status(), [200, 204])) {
                        return;
                    }
                    
                    // Header 方式 + 自定义UA
                    $authHeaders = array_merge($baseHeaders, [  // ← 合并
                        'X-Emby-Token' => $apiKey,
                        'Authorization' => 'MediaBrowser Token="' . $apiKey . '"'
                    ]);
                    
                    $resp = Http::timeout($this->timeout)
                        ->withoutVerifying()
                        ->withHeaders($authHeaders)  // ← 使用合并后的
                        ->post($tUrl);
                    
                    if ($resp->successful() || in_array($resp->status(), [200, 204])) {
                        return;
                    }
                } catch (Exception $inner2) {
                    continue;
                }
            }
    
            return;
    
        } catch (Exception $e) {
            throw new Exception('Emby服务器更新用户状态失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 更新Emby服务器用户密码（支持自定义User-Agent）
     */
    private function updateUserPasswordOnEmbyServer($embyServer, $embyUserId, $newPassword)
    {
        try {
            $baseUrl = rtrim($embyServer->url, '/');
            $apiKey = $embyServer->api_key;
    
            // ============ 构建基础请求头（支持自定义 User-Agent）============
            $baseHeaders = [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json; charset=UTF-8'
            ];
            
            // ← 添加自定义 User-Agent（如果配置了）
            if (!empty($embyServer->user_agent)) {
                $baseHeaders['User-Agent'] = $embyServer->user_agent;
            }
    
            $endpoints = [
                '/Users/' . $embyUserId . '/Password',
                '/emby/Users/' . $embyUserId . '/Password'
            ];
    
            $payloadVariants = [
                'python_like' => [ 'Id' => (string)$embyUserId, 'NewPw' => (string)$newPassword ],
                'with_reset' => [ 'Id' => (string)$embyUserId, 'NewPw' => (string)$newPassword, 'ResetPassword' => true ],
                'simple' => [ 'NewPw' => (string)$newPassword ],
                'alt' => [ 'Password' => (string)$newPassword, 'ResetPassword' => true ],
            ];
    
            $methods = ['POST', 'PUT'];
    
            foreach ($endpoints as $endpoint) {
                foreach ($methods as $method) {
                    foreach ($payloadVariants as $variant => $data) {
                        $url = $baseUrl . $endpoint;
                        try {
                            // Query 方式（使用自定义UA）
                            $resp = Http::timeout($this->timeout)
                                ->withoutVerifying()
                                ->asJson()
                                ->withHeaders($baseHeaders)  // ← 使用包含自定义UA的headers
                                ->{strtolower($method)}($url . '?api_key=' . $apiKey, $data);
    
                            if (!$resp->successful()) {
                                // Header 方式（合并认证头和自定义UA）
                                $authHeaders = array_merge($baseHeaders, [
                                    'X-Emby-Token' => $apiKey,
                                    'Authorization' => 'MediaBrowser Token="' . $apiKey . '"'
                                ]);
                                
                                $resp = Http::timeout($this->timeout)
                                    ->withoutVerifying()
                                    ->asJson()
                                    ->withHeaders($authHeaders)  // ← 使用合并后的headers
                                    ->{strtolower($method)}($url, $data);
                            }
    
                            if ($resp->successful() || in_array($resp->status(), [200, 204])) {
                                return;
                            }
                        } catch (Exception $e) {
                            // 继续尝试其他组合
                            continue;
                        }
                    }
                }
            }
    
            throw new Exception('所有设置密码请求均失败');
    
        } catch (Exception $e) {
            Log::error('更新Emby用户密码失败', [
                'error' => $e->getMessage(),
                'emby_user_id' => $embyUserId
            ]);
            throw new Exception('Emby服务器更新密码失败: ' . $e->getMessage());
        }
    }

    /**
     * 在Emby服务器创建用户（修复密码设置和权限问题，支持自定义User-Agent）
     */
    private function createUserOnEmbyServer($embyServer, $username, $password)
    {
        try {
            $baseUrl = rtrim($embyServer->url, '/');
            $apiKey = $embyServer->api_key;
            $url = $baseUrl . '/emby/Users/New';
            
            // 修复：正确设置用户创建请求数据
            $requestData = [
                'Name' => $username,
                'Password' => $password,
                'CopyFromUserId' => null
            ];
    
            // ============ 构建请求头（支持自定义 User-Agent）============
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ];
            
            // ← 添加自定义 User-Agent（如果配置了）
            if (!empty($embyServer->user_agent)) {
                $headers['User-Agent'] = $embyServer->user_agent;
            }
    
            // 方式1: 使用查询参数传递API密钥
            $response = Http::timeout($this->timeout)
                ->withHeaders($headers)  // ← 添加headers
                ->post($url . '?api_key=' . $apiKey, $requestData);
    
            if (!$response->successful()) {
                // 如果第一种方式失败，尝试其他方式
                if ($response->status() === 401) {
                    
                    // 方式2: 使用Header传递API密钥
                    $authHeaders = array_merge($headers, [  // ← 合并自定义headers
                        'X-Emby-Token' => $apiKey,
                        'X-MediaBrowser-Token' => $apiKey,
                    ]);
                    
                    $response = Http::timeout($this->timeout)
                        ->withHeaders($authHeaders)  // ← 使用合并后的headers
                        ->post($url, $requestData);
                }
                
                // 如果仍然失败，抛出详细错误
                if (!$response->successful()) {
                    $errorBody = $response->body();
                    $statusCode = $response->status();
                    
                    Log::error('所有API调用方式都失败', [
                        'status_code' => $statusCode,
                        'response_body' => $errorBody
                    ]);
                    
                    // 解析HTML实体编码的错误消息
                    $errorMessage = html_entity_decode($errorBody);
                    
                    throw new Exception("Emby服务器错误 (HTTP {$statusCode}): {$errorMessage}");
                }
            }
    
            $userData = $response->json();

            // 获取用户ID
            $userId = null;
            if (isset($userData['Id'])) {
                $userId = $userData['Id'];
            } elseif (isset($userData['id'])) {
                $userId = $userData['id'];
            } elseif (isset($userData['ID'])) {
                $userId = $userData['ID'];
            } else {
                Log::error('Emby响应数据异常', ['response' => $userData]);
                throw new Exception('Emby服务器返回数据异常，无法获取用户ID');
            }
    
            // 等待一段时间确保用户创建完成
            sleep(1);
    
            // 设置用户密码：优先使用健壮两步法，并加入重试与延迟
            $setOk = false;
            for ($attempt = 1; $attempt <= 3 && !$setOk; $attempt++) {
                try {
                    if ($attempt > 1) {
                        sleep($attempt); // 等待Emby落盘，逐次增加延迟
                    }
                    $this->updateUserPasswordOnEmbyServer($embyServer, $userId, $password);
                    $setOk = true;
                } catch (Exception $e) {
                    // 继续重试
                }
            }

            // 设置用户权限策略（修复权限问题）
            try {
                $this->setUserPolicy($embyServer, $userId);
            } catch (Exception $e) {
                // 不抛出异常，因为用户已经创建成功
            }
    
            return $userId;
    
        } catch (Exception $e) {
            Log::error('创建Emby用户失败', [
                'error' => $e->getMessage(),
                'server_url' => $embyServer->url ?? 'Unknown',
                'username' => $username
            ]);
            
            throw $e; // 重新抛出异常，保持原始错误信息
        }
    }

    /**
     * 设置Emby用户密码（新增方法）
     */
    private function setUserPassword($embyServer, $userId, $password)
    {
        $baseUrl = rtrim($embyServer->url, '/');
        $apiKey = $embyServer->api_key;
        $url = $baseUrl . '/emby/Users/' . $userId . '/Password';

        $headers = ['Content-Type' => 'application/json'];
        if (!empty($embyServer->user_agent)) {
            $headers['User-Agent'] = $embyServer->user_agent;
        }

        $passwordData = [
            'Id' => $userId,
            'CurrentPw' => '',
            'NewPw' => $password,
            'ResetPassword' => true
        ];

        $response = Http::timeout($this->timeout)
            ->withHeaders($headers)
            ->post($url . '?api_key=' . $apiKey, $passwordData);

        if (!$response->successful()) {
            if ($response->status() === 401) {
                $authHeaders = array_merge($headers, ['X-Emby-Token' => $apiKey]);
                $response = Http::timeout($this->timeout)
                    ->withHeaders($authHeaders)
                    ->post($url, $passwordData);
            }
            if (!$response->successful()) {
                throw new Exception("设置用户密码失败 HTTP {$response->status()}: {$response->body()}");
            }
        }
    }

    /**
     * 设置Emby用户权限策略（修复权限设置）
     */
    private function setUserPolicy($embyServer, $userId)
    {
        $baseUrl = rtrim($embyServer->url, '/');
        $apiKey = $embyServer->api_key;
        $url = $baseUrl . '/emby/Users/' . $userId . '/Policy';
    
        // ← 构建 headers（包含自定义UA）
        $headers = ['Content-Type' => 'application/json'];
        if (!empty($embyServer->user_agent)) {
            $headers['User-Agent'] = $embyServer->user_agent;
        }
    
        $policy = $this->getDefaultUserPolicy();
    
        // 方式1: 使用查询参数 + 自定义UA
        $response = Http::timeout($this->timeout)
            ->withHeaders($headers)  // ← 添加
            ->post($url . '?api_key=' . $apiKey, $policy);
    
        if (!$response->successful()) {
            // 方式2: 使用Header + 自定义UA
            if ($response->status() === 401) {
                $authHeaders = array_merge($headers, [  // ← 合并
                    'X-Emby-Token' => $apiKey
                ]);
                
                $response = Http::timeout($this->timeout)
                    ->withHeaders($authHeaders)  // ← 使用合并后的
                    ->post($url, $policy);
            }
            
            if (!$response->successful()) {
                throw new Exception("设置用户策略失败 HTTP {$response->status()}: {$response->body()}");
            }
        }
    }
    /**
     * 从Emby服务器删除用户 - 修复版本（支持自定义User-Agent）
     */
    private function deleteUserFromEmbyServer($embyServer, $embyUserId)
    {
        try {
            $baseUrl = rtrim($embyServer->url, '/');
            $url = $baseUrl . '/emby/Users/' . $embyUserId;
            $apiKey = $embyServer->api_key;
    
            // ============ 构建请求头（支持自定义 User-Agent）============
            $headers = [
                'Accept' => 'application/json'
            ];
            
            // ← 添加自定义 User-Agent（如果配置了）
            if (!empty($embyServer->user_agent)) {
                $headers['User-Agent'] = $embyServer->user_agent;
            }
    
            // 使用DELETE方法删除用户
            $response = Http::timeout($this->timeout)
                ->withHeaders($headers)
                ->delete($url . '?api_key=' . $apiKey);

            if (!$response->successful()) {
                if ($response->status() === 401) {
                    $authHeaders = array_merge($headers, [
                        'X-Emby-Token' => $apiKey
                    ]);

                    $response = Http::timeout($this->timeout)
                        ->withHeaders($authHeaders)
                        ->delete($url);
                }
                
                if (!$response->successful()) {
                    throw new Exception("HTTP {$response->status()}: {$response->body()}");
                }
            }
    
        } catch (Exception $e) {
            throw new Exception('Emby服务器删除用户失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取Emby服务器媒体库数量
     */
    public function getLibraryCount($embyServer)
    {
        try {
            $baseUrl = rtrim($embyServer->url, '/');
            $apiKey = $embyServer->api_key;

            $baseHeaders = ['Accept' => 'application/json'];
            if (!empty($embyServer->user_agent)) {
                $baseHeaders['User-Agent'] = $embyServer->user_agent;
            }

            $endpoints = [
                '/Library/VirtualFolders',
                '/emby/Library/VirtualFolders',
                '/Library/MediaFolders',
                '/emby/Library/MediaFolders'
            ];

            foreach ($endpoints as $endpoint) {
                try {
                    $url = $baseUrl . $endpoint;

                    $response = Http::timeout($this->timeout)
                        ->withoutVerifying()
                        ->withHeaders($baseHeaders)
                        ->get($url . '?api_key=' . $apiKey);

                    if (!$response->successful()) {
                        $authHeaders = array_merge($baseHeaders, [
                            'X-Emby-Token' => $apiKey,
                            'Authorization' => 'MediaBrowser Token="' . $apiKey . '"'
                        ]);
                        $response = Http::timeout($this->timeout)
                            ->withoutVerifying()
                            ->withHeaders($authHeaders)
                            ->get($url);
                    }

                    if ($response->successful()) {
                        $data = $response->json();
                        if (is_array($data)) {
                            return count($data);
                        } elseif (isset($data['Items']) && is_array($data['Items'])) {
                            return count($data['Items']);
                        } elseif (isset($data['VirtualFolders']) && is_array($data['VirtualFolders'])) {
                            return count($data['VirtualFolders']);
                        }
                    }
                } catch (Exception $e) {
                    Log::warning('获取媒体库数量失败', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);
                    continue;
                }
            }

            return 0;
        } catch (Exception $e) {
            Log::error('获取媒体库数量异常', ['error' => $e->getMessage(), 'server_id' => $embyServer->id ?? 'unknown']);
            return 0;
        }
    }

    /**
     * 获取Emby服务器详细媒体统计信息
     */
    public function getMediaStatistics($embyServer)
    {
        try {
            $baseUrl = rtrim($embyServer->url, '/');
            $apiKey = $embyServer->api_key;

            $baseHeaders = ['Accept' => 'application/json'];
            if (!empty($embyServer->user_agent)) {
                $baseHeaders['User-Agent'] = $embyServer->user_agent;
            }

            $endpoints = ['/Items/Counts', '/emby/Items/Counts'];

            foreach ($endpoints as $endpoint) {
                try {
                    $url = $baseUrl . $endpoint;

                    $response = Http::timeout($this->timeout)
                        ->withoutVerifying()
                        ->withHeaders($baseHeaders)
                        ->get($url . '?api_key=' . $apiKey);

                    if (!$response->successful()) {
                        $authHeaders = array_merge($baseHeaders, [
                            'X-Emby-Token' => $apiKey,
                            'Authorization' => 'MediaBrowser Token="' . $apiKey . '"'
                        ]);
                        $response = Http::timeout($this->timeout)
                            ->withoutVerifying()
                            ->withHeaders($authHeaders)
                            ->get($url);
                    }

                    if ($response->successful()) {
                        $data = $response->json();
                        return [
                            'movie_count'       => $data['MovieCount'] ?? 0,
                            'series_count'      => $data['SeriesCount'] ?? 0,
                            'episode_count'     => $data['EpisodeCount'] ?? 0,
                            'song_count'        => $data['SongCount'] ?? 0,
                            'album_count'       => $data['AlbumCount'] ?? 0,
                            'artist_count'      => $data['ArtistCount'] ?? 0,
                            'music_video_count' => $data['MusicVideoCount'] ?? 0,
                            'book_count'        => $data['BookCount'] ?? 0,
                            'game_count'        => $data['GameCount'] ?? 0,
                            'trailer_count'     => $data['TrailerCount'] ?? 0,
                            'box_set_count'     => $data['BoxSetCount'] ?? 0,
                            'program_count'     => $data['ProgramCount'] ?? 0,
                            'game_system_count' => $data['GameSystemCount'] ?? 0,
                            'total_item_count'  => $data['ItemCount'] ?? 0,
                            'is_favorite_count' => $data['IsFavorite'] ?? 0
                        ];
                    }
                } catch (Exception $e) {
                    Log::warning('获取媒体统计信息失败', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);
                    continue;
                }
            }

            return $this->getEmptyMediaStatistics();
        } catch (Exception $e) {
            Log::error('获取媒体统计信息异常', ['error' => $e->getMessage(), 'server_id' => $embyServer->id ?? 'unknown']);
            return $this->getEmptyMediaStatistics();
        }
    }

    /**
     * 获取空的媒体统计信息
     */
    private function getEmptyMediaStatistics()
    {
        return [
            'movie_count'       => 0,
            'series_count'      => 0,
            'episode_count'     => 0,
            'song_count'        => 0,
            'album_count'       => 0,
            'artist_count'      => 0,
            'music_video_count' => 0,
            'book_count'        => 0,
            'game_count'        => 0,
            'trailer_count'     => 0,
            'box_set_count'     => 0,
            'program_count'     => 0,
            'game_system_count' => 0,
            'total_item_count'  => 0,
            'is_favorite_count' => 0
        ];
    }

    /**
     * 获取默认Emby用户权限配置（修复权限设置）
     */
    private function getDefaultUserPolicy()
    {
        return [
            'IsAdministrator' => false,
            'IsHidden' => true,
            'IsHiddenRemotely' => true,
            'IsHiddenFromUnusedDevices' => true,
            'IsDisabled' => false,
            'BlockedTags' => [],
            'IsTagBlockingModeInclusive' => false,
            'IncludeTags' => [],
            'EnableUserPreferenceAccess' => true,
            'AccessSchedules' => [],
            'BlockUnratedItems' => [],
            'EnableRemoteControlOfOtherUsers' => false,
            'EnableSharedDeviceControl' => true,
            'EnableRemoteAccess' => true,
            'EnableLiveTvManagement' => false,
            'EnableLiveTvAccess' => false,
            'EnableMediaPlayback' => true,
            'EnableAudioPlaybackTranscoding' => false,
            'EnableVideoPlaybackTranscoding' => false,
            'EnablePlaybackRemuxing' => false,
            'EnableContentDeletion' => false,
            'EnableContentDeletionFromFolders' => [],
            'EnableContentDownloading' => false,
            'EnableSubtitleDownloading' => false,
            'EnableSubtitleManagement' => false,
            'EnableSyncTranscoding' => false,
            'EnableMediaConversion' => false,
            'EnabledChannels' => [],
            'EnableAllChannels' => true,
            'EnabledFolders' => [],
            'EnableAllFolders' => true,
            'InvalidLoginAttemptCount' => 0,
            'EnablePublicSharing' => false,
            'RemoteClientBitrateLimit' => 0,
            'ExcludedSubFolders' => [],
            'SimultaneousStreamLimit' => '0',
            'EnabledDevices' => [],
            'EnableAllDevices' => true,
            'AuthenticationProviderId' => 'Emby.Server.Implementations.Library.DefaultAuthenticationProvider'
        ];
    }

    /**
     * 清理过期账号（供 emby:cleanup 命令调用）
     * 只删除：Emby 端已过期+已禁用，且用户订阅也确实已过期/无套餐/被封禁的账号
     */
    public function cleanupExpiredAccounts(int $days = 30): array
    {
        $cutoffDate = now()->subDays($days);

        $expiredEmbyUsers = DB::table('v2_emby_users as eu')
            ->join('v2_user as u', 'eu.user_id', '=', 'u.id')
            ->whereNotNull('eu.expired_at')
            ->where('eu.expired_at', '<', $cutoffDate)
            ->where('eu.status', 0)
            ->where(function ($q) {
                $q->whereNull('u.plan_id')
                  ->orWhere('u.expired_at', '<', time())
                  ->orWhere('u.banned', 1);
            })
            ->select(['eu.user_id', 'eu.emby_server_id', 'eu.username'])
            ->get();

        $results = [
            'total'   => $expiredEmbyUsers->count(),
            'success' => 0,
            'failed'  => 0,
            'errors'  => [],
        ];

        foreach ($expiredEmbyUsers as $embyUser) {
            try {
                $res = $this->deleteEmbyUser($embyUser->user_id, $embyUser->emby_server_id);
                if (!empty($res['success'])) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "User {$embyUser->user_id}: " . ($res['message'] ?? '删除失败');
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "User {$embyUser->user_id}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * 检查服务器健康状态（供 emby:health-check 命令调用）
     */
    public function checkServerHealth($serverId = null): array
    {
        $query = DB::table('v2_emby_servers')->where('status', 1);
        if ($serverId) {
            $query->where('id', $serverId);
        }
        $servers = $query->get();

        $results = [];
        foreach ($servers as $server) {
            try {
                $baseUrl  = rtrim($server->url, '/');
                $headers  = ['Accept' => 'application/json'];
                if (!empty($server->user_agent)) {
                    $headers['User-Agent'] = $server->user_agent;
                }

                $response = Http::timeout(8)
                    ->withoutVerifying()
                    ->withHeaders($headers)
                    ->get($baseUrl . '/emby/System/Info?api_key=' . $server->api_key);

                if ($response->successful()) {
                    $results[] = [
                        'server_id'   => $server->id,
                        'server_name' => $server->name,
                        'status'      => 'online',
                        'info'        => $response->json() ?? [],
                        'error'       => null,
                    ];
                } else {
                    $results[] = [
                        'server_id'   => $server->id,
                        'server_name' => $server->name,
                        'status'      => 'offline',
                        'info'        => [],
                        'error'       => "HTTP {$response->status()}",
                    ];
                }
            } catch (Exception $e) {
                $results[] = [
                    'server_id'   => $server->id,
                    'server_name' => $server->name,
                    'status'      => 'offline',
                    'info'        => [],
                    'error'       => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * 重新计算并更新各服务器的实际用户数（供 emby:sync 命令调用）
     */
    public function syncServerUserCounts($serverId = null): void
    {
        $query = DB::table('v2_emby_servers');
        if ($serverId) {
            $query->where('id', $serverId);
        }
        $serverIds = $query->pluck('id');

        foreach ($serverIds as $id) {
            $count = DB::table('v2_emby_users')
                ->where('emby_server_id', $id)
                ->where('status', 1)
                ->count();

            DB::table('v2_emby_servers')
                ->where('id', $id)
                ->update(['current_users' => $count, 'updated_at' => now()]);
        }
    }
}