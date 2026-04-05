<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\ServerAnytls;
use App\Models\ServerHysteria;
use App\Models\ServerLog;
use App\Models\ServerShadowsocks;
use App\Models\ServerTrojan;
use App\Models\ServerTuic;
use App\Models\ServerV2node;
use App\Models\ServerVless;
use App\Models\ServerVmess;
use App\Models\StatUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class StatController extends Controller
{
    public function getTrafficLog(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, '用户不存在');
        }

        $cycleStart = $this->getCycleStart($user);
        $cycleEnd   = $this->getCycleEnd($user, $cycleStart);

        $builder = StatUser::select([
            'u',
            'd',
            'record_at',
            'user_id',
            'server_rate'
        ])
            ->where('user_id', $user->id)
            ->where('record_at', '>=', $cycleStart)
            ->orderBy('record_at', 'DESC');

        return response([
            'data' => [
                'cycle_start' => $cycleStart,
                'cycle_end'   => $cycleEnd,
                'logs'        => $builder->get(),
            ]
        ]);
    }

    public function getNodeTrafficLog(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, '用户不存在');
        }

        $cycleStart = $this->getCycleStart($user);
        $cycleEnd   = $this->getCycleEnd($user, $cycleStart);

        // 查询当前周期内该用户的分节点流量
        $logs = DB::table('v2_server_log')
            ->select([
                'server_id',
                'server_type',
                DB::raw('SUM(u) as u'),
                DB::raw('SUM(d) as d'),
                DB::raw('SUM(u) + SUM(d) as total'),
            ])
            ->where('user_id', $user->id)
            ->where('log_at', '>=', $cycleStart)
            ->groupBy('server_id', 'server_type')
            ->orderBy(DB::raw('SUM(u) + SUM(d)'), 'DESC')
            ->get();

        // 收集各协议下需要查找名称的 server_id
        $idsByType = [];
        foreach ($logs as $log) {
            $idsByType[$log->server_type][] = $log->server_id;
        }

        // 按协议批量查节点名称
        $nameMap = $this->fetchServerNames($idsByType);

        $result = $logs->map(function ($log) use ($nameMap) {
            $key = $log->server_type . '_' . $log->server_id;
            return [
                'server_id'   => $log->server_id,
                'server_name' => $nameMap[$key] ?? ('节点#' . $log->server_id),
                'server_type' => $log->server_type,
                'u'           => (int) $log->u,
                'd'           => (int) $log->d,
                'total'       => (int) $log->total,
            ];
        });

        return response([
            'data' => [
                'cycle_start' => $cycleStart,
                'cycle_end'   => $cycleEnd,
                'logs'        => $result,
            ]
        ]);
    }

    /**
     * 根据用户套餐的重置方式计算当前周期起始时间戳
     */
    private function getCycleStart(User $user): int
    {
        $resetMethod = null;
        if ($user->plan_id) {
            $plan = Plan::find($user->plan_id);
            $resetMethod = $plan ? $plan->reset_traffic_method : null;
        }
        if ($resetMethod === null) {
            $resetMethod = (int) config('v2board.reset_traffic_method', 0);
        }

        switch ((int) $resetMethod) {
            case 0: // 每月1号
                return strtotime(date('Y-m-01 00:00:00'));

            case 1: // 按到期日当天
                if (!$user->expired_at) {
                    return strtotime(date('Y-m-01 00:00:00'));
                }
                $expireDay = (int) date('d', $user->expired_at);
                $today = (int) date('d');
                if ($today >= $expireDay) {
                    // 本月的到期日
                    $cycleStart = strtotime(date('Y-m-' . sprintf('%02d', $expireDay)));
                } else {
                    // 上月的到期日
                    $cycleStart = strtotime(date('Y-m-' . sprintf('%02d', $expireDay), strtotime('last month')));
                }
                return $cycleStart;

            case 2: // 不重置
                return 0;

            case 3: // 每年1月1日
                return strtotime(date('Y-01-01 00:00:00'));

            case 4: // 按到期年份当天
                if (!$user->expired_at) {
                    return strtotime(date('Y-01-01 00:00:00'));
                }
                $expireMd = date('m-d', $user->expired_at);
                $todayMd  = date('m-d');
                if ($todayMd >= $expireMd) {
                    return strtotime(date('Y-') . $expireMd);
                } else {
                    return strtotime((date('Y') - 1) . '-' . $expireMd);
                }

            default:
                return strtotime(date('Y-m-01 00:00:00'));
        }
    }

    /**
     * 根据周期起始时间计算周期截止时间戳（当天 23:59:59）
     */
    private function getCycleEnd(User $user, int $cycleStart): int
    {
        $resetMethod = null;
        if ($user->plan_id) {
            $plan = Plan::find($user->plan_id);
            $resetMethod = $plan ? $plan->reset_traffic_method : null;
        }
        if ($resetMethod === null) {
            $resetMethod = (int) config('v2board.reset_traffic_method', 0);
        }

        switch ((int) $resetMethod) {
            case 0: // 每月1号 → 本月最后一天
                return strtotime(date('Y-m-t 23:59:59'));

            case 1: // 按到期日当天 → 下一个到期日前一天
                if (!$user->expired_at) {
                    return strtotime(date('Y-m-t 23:59:59'));
                }
                $expireDay = (int) date('d', $user->expired_at);
                $today = (int) date('d');
                if ($today >= $expireDay) {
                    // 下月的到期日前一秒
                    $next = strtotime(date('Y-m-' . sprintf('%02d', $expireDay), strtotime('next month')));
                } else {
                    // 本月的到期日前一秒
                    $next = strtotime(date('Y-m-' . sprintf('%02d', $expireDay)));
                }
                return $next - 1;

            case 2: // 不重置 → 今天结束
                return strtotime(date('Y-m-d 23:59:59'));

            case 3: // 每年1月1日 → 本年最后一天
                return strtotime(date('Y-12-31 23:59:59'));

            case 4: // 按到期年份当天 → 下一个周年前一秒
                if (!$user->expired_at) {
                    return strtotime(date('Y-12-31 23:59:59'));
                }
                $expireMd = date('m-d', $user->expired_at);
                $todayMd  = date('m-d');
                if ($todayMd >= $expireMd) {
                    $next = strtotime((date('Y') + 1) . '-' . $expireMd);
                } else {
                    $next = strtotime(date('Y') . '-' . $expireMd);
                }
                return $next - 1;

            default:
                return strtotime(date('Y-m-t 23:59:59'));
        }
    }

    /**
     * 批量查各协议节点名称，返回 "method_serverId" => name 的映射
     */
    private function fetchServerNames(array $idsByType): array
    {
        $modelMap = [
            'vmess'       => ServerVmess::class,
            'vless'       => ServerVless::class,
            'trojan'      => ServerTrojan::class,
            'shadowsocks' => ServerShadowsocks::class,
            'hysteria'    => ServerHysteria::class,
            'tuic'        => ServerTuic::class,
            'anytls'      => ServerAnytls::class,
            'v2node'      => ServerV2node::class,
        ];

        $nameMap = [];
        foreach ($idsByType as $type => $ids) {
            $class = $modelMap[$type] ?? null;
            if (!$class) continue;
            $servers = $class::whereIn('id', array_unique($ids))->select(['id', 'name'])->get();
            foreach ($servers as $server) {
                $nameMap[$type . '_' . $server->id] = $server->name;
            }
        }
        return $nameMap;
    }

    public function getSubSecurity(Request $request)
    {
        $userId  = $request->user['id'];
        $enabled = (bool)(int)config('v2board.sub_ip_limit_enable', 0);

        $limits = [
            'ip_count'       => (int)config('v2board.sub_ip_limit_count', 10),
            'rate_per_minute'=> (int)config('v2board.sub_rate_limit_count', 10),
            'ban_hours'      => (int)config('v2board.sub_ip_limit_ban_hours', 24),
        ];

        // 封禁状态
        $banKey     = 'sub:banned:' . $userId;
        $banned     = (bool)Redis::exists($banKey);
        $banTtl     = $banned ? (int)Redis::ttl($banKey) : 0;
        $banRemainingHours = $banTtl > 0 ? ceil($banTtl / 3600) : 0;
        $banType    = $banned ? (string)Redis::get($banKey) : null; // 'rate' 或 'ip'

        // 24小时拉取次数、独立IP数、近1小时拉取次数（均从日志表查询，数据准确）
        // 统计窗口取「24小时前」与「最后解封时间」中较新的那个，与封禁判断逻辑保持一致
        $since24h  = now()->subHours(24);
        $since1h   = now()->subHour();
        $lastUnban = DB::table('v2_subscribe_unban_log')
            ->where('user_id', $userId)
            ->max('created_at');
        $sinceTime = $lastUnban ? max(\Carbon\Carbon::parse($lastUnban), $since24h) : $since24h;

        // blocked=0 排除封禁事件日志，只统计真实拉取请求
        $pull24h = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $sinceTime)
            ->where('blocked', 0)
            ->count();

        $ipCount = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $sinceTime)
            ->where('blocked', 0)
            ->distinct('ip')
            ->count('ip');

        $pull1h = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since1h)
            ->where('blocked', 0)
            ->count();

        // 解封资格信息
        $unban = $this->getUnbanEligibility($userId);

        return response([
            'data' => [
                'enabled'             => $enabled,
                'limits'              => $limits,
                'current'             => [
                    'ip_count' => $ipCount,
                    'pull_24h' => $pull24h,
                    'pull_1h'  => $pull1h,
                ],
                'banned'              => $banned,
                'ban_type'            => $banType,
                'ban_remaining_hours' => $banRemainingHours,
                'unban'               => $unban,
            ]
        ]);
    }

    public function requestUnban(Request $request)
    {
        $userId = $request->user['id'];
        $banKey = 'sub:banned:' . $userId;

        if (!Redis::exists($banKey)) {
            abort(400, '当前未处于封禁状态');
        }

        $eligibility = $this->getUnbanEligibility($userId);

        if ($eligibility['needs_manual_review']) {
            abort(403, '申请次数过多，请联系客服处理');
        }

        if (!$eligibility['can_request']) {
            abort(429, "冷却中，请 {$eligibility['cooldown_hours']} 小时后再试");
        }

        // 执行解封：仅删除 ban key，拉取日志保留用于审计
        // 解封记录写入后，统计窗口会自动从解封时间起算，不会立即再次触发封禁
        Redis::del($banKey);

        DB::table('v2_subscribe_unban_log')->insert([
            'user_id'    => $userId,
            'created_at' => now(),
        ]);

        // TG 通知管理员
        if (config('v2board.telegram_bot_enable', 0)) {
            try {
                $user  = \App\Models\User::find($userId);
                $email = $user ? $user->email : "uid:{$userId}";
                (new \App\Services\TelegramService())->sendMessageWithAdmin(
                    "🔓 用户自助解封订阅限制\n用户：{$email}\n时间：" . date('Y-m-d H:i:s')
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Unban TG notify failed: ' . $e->getMessage());
            }
        }

        return response(['data' => true]);
    }

    private function getUnbanEligibility(int $userId): array
    {
        $intervalDays = max(1, (int)config('v2board.sub_unban_interval_days', 3));
        $maxCount     = max(1, (int)config('v2board.sub_unban_max_count', 3));

        $totalCount = DB::table('v2_subscribe_unban_log')
            ->where('user_id', $userId)
            ->count();

        if ($totalCount >= $maxCount) {
            return [
                'can_request'         => false,
                'needs_manual_review' => true,
                'cooldown_hours'      => 0,
                'requests_used'       => $totalCount,
                'requests_max'        => $maxCount,
                'next_available_at'   => null,
            ];
        }

        $last = DB::table('v2_subscribe_unban_log')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->value('created_at');

        $cooldownHours    = 0;
        $nextAvailableAt  = null;
        $canRequest       = true;

        if ($last) {
            $nextAvailable = \Carbon\Carbon::parse($last)->addDays($intervalDays);
            if (now()->lt($nextAvailable)) {
                $canRequest      = false;
                $cooldownHours   = (int)now()->diffInHours($nextAvailable, false) + 1;
                $nextAvailableAt = $nextAvailable->toDateTimeString();
            }
        }

        return [
            'can_request'         => $canRequest,
            'needs_manual_review' => false,
            'cooldown_hours'      => $cooldownHours,
            'requests_used'       => $totalCount,
            'requests_max'        => $maxCount,
            'next_available_at'   => $nextAvailableAt,
        ];
    }
}
