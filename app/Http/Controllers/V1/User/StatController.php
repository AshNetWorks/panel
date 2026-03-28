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

class StatController extends Controller
{
    public function getTrafficLog(Request $request)
    {
        $thirtyDaysAgo = strtotime('-30 days', time());
        $builder = StatUser::select([
            'u',
            'd',
            'record_at',
            'user_id',
            'server_rate'
        ])
            ->where('user_id', $request->user['id'])
            ->where('record_at', '>=', $thirtyDaysAgo)
            ->orderBy('record_at', 'DESC');
        return response([
            'data' => $builder->get()
        ]);
    }

    public function getNodeTrafficLog(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, '用户不存在');
        }

        $cycleStart = $this->getCycleStart($user);

        // 查询当前周期内该用户的分节点流量，按节点+协议汇总
        $logs = ServerLog::select([
                'server_id',
                'method',
                DB::raw('SUM(u) as u'),
                DB::raw('SUM(d) as d'),
                DB::raw('SUM(u) + SUM(d) as total'),
            ])
            ->where('user_id', $user->id)
            ->where('log_at', '>=', $cycleStart)
            ->groupBy('server_id', 'method')
            ->orderBy(DB::raw('SUM(u) + SUM(d)'), 'DESC')
            ->get();

        // 收集各协议下需要查找名称的 server_id
        $idsByType = [];
        foreach ($logs as $log) {
            $idsByType[$log->method][] = $log->server_id;
        }

        // 按协议批量查节点名称
        $nameMap = $this->fetchServerNames($idsByType);

        $result = $logs->map(function ($log) use ($nameMap) {
            $key = $log->method . '_' . $log->server_id;
            return [
                'server_id'   => $log->server_id,
                'server_name' => $nameMap[$key] ?? ('节点#' . $log->server_id),
                'server_type' => $log->method,
                'u'           => (int) $log->u,
                'd'           => (int) $log->d,
                'total'       => (int) $log->total,
            ];
        });

        return response([
            'data' => [
                'cycle_start' => $cycleStart,
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
}
