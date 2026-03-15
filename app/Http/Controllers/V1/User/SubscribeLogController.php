<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscribeLogController extends Controller
{
    /**
     * 获取当前用户的订阅拉取记录（带分页）
     */
    public function fetch(Request $request)
    {
        $userId = $request->user['id'] ?? null;
        if (!$userId) {
            abort(500, __('The user does not exist'));
        }
    
        $current = (int)$request->input('current', 1);
        $pageSize = (int)$request->input('pageSize', 20);
        if ($current < 1) $current = 1;
    
        $total = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $userId)
            ->count();
    
        // 推荐用数组风格
        $logs = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->offset(($current - 1) * $pageSize)
            ->limit($pageSize)
            ->get()
            ->toArray();
    
        // 只用数组方式访问所有字段，100%不会再报错
        foreach ($logs as &$log) {
            $log = (array)$log;
            $log['created_at'] = strtotime($log['created_at']);
            $log['client_type'] = $this->parseClientType($log['user_agent'] ?? '');
            $log['country'] = $log['country'] ?? '未知';
            $log['city'] = $log['city'] ?? '未知';
        }
    
        return response([
            'data' => $logs,
            'total' => $total
        ]);
    }

    /**
     * 订阅拉取统计
     */
    public function statistics(Request $request)
    {
        $userId = $request->user['id'] ?? null;
        if (!$userId) {
            abort(500, __('The user does not exist'));
        }

        // 近30天拉取次数
        $recentCount = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        // 客户端类型
        $userAgents = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $userId)
            ->pluck('user_agent');
        $clientTypes = collect($userAgents)->map(function ($ua) {
            return $this->parseClientType($ua ?? '');
        })->unique()->filter()->values();

        // 最后拉取时间
        $lastSubscribe = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->first();

        return response([
            'data' => [
                'recent_count' => $recentCount,
                'client_types' => $clientTypes,
                'last_subscribe_time' => $lastSubscribe ? strtotime($lastSubscribe->created_at) : null
            ]
        ]);
    }

    /**
     * 客户端类型解析
     */
    private function parseClientType($userAgent)
    {
        $ua = strtolower($userAgent ?? '');
        if (strpos($ua, 'clash') !== false) return 'Clash';
        if (strpos($ua, 'shadowrocket') !== false) return 'Shadowrocket';
        if (strpos($ua, 'quantumult') !== false) return 'Quantumult';
        if (strpos($ua, 'surge') !== false) return 'Surge';
        if (strpos($ua, 'v2ray') !== false) return 'V2Ray';
        if (strpos($ua, 'sing-box') !== false || strpos($ua, 'singbox') !== false) return 'Sing-Box';
        if (strpos($ua, 'stash') !== false) return 'Stash';
        return 'Unknown';
    }
}
