<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscribeWhitelistController extends Controller
{
    /**
     * 与 ClientController::$chinaOnlyEnabled 保持一致
     * true: 仅允许中国大陆IP加入白名单
     */
    private $chinaOnlyEnabled = false;
    /**
     * 获取白名单列表及配额信息
     */
    public function fetch(Request $request)
    {
        $userId = $request->user['id'] ?? null;
        if (!$userId) {
            abort(500, __('The user does not exist'));
        }
        $user = User::find($userId);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }

        $list = DB::table('v2_subscribe_whitelist')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'ip', 'country', 'province', 'city', 'isp', 'remark', 'created_at'])
            ->toArray();

        $limit = $this->getLimit($user);

        $blockedLogs = DB::table('v2_subscribe_pull_log')
            ->where('user_id', $userId)
            ->where('blocked', 1)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get(['ip', 'os', 'device', 'country', 'city', 'block_reason', 'created_at'])
            ->toArray();

        foreach ($blockedLogs as &$log) {
            $log = (array)$log;
            $log['created_at'] = strtotime($log['created_at']);
        }
        unset($log);

        return response([
            'data' => [
                'list'         => $list,
                'limit'        => $limit,
                'used'         => count($list),
                'blocked_logs' => $blockedLogs,
            ]
        ]);
    }

    /**
     * 添加白名单IP
     */
    public function save(Request $request)
    {
        $userId = $request->user['id'] ?? null;
        if (!$userId) {
            abort(500, __('The user does not exist'));
        }
        $user = User::find($userId);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }

        if (!$this->hasActivePlan($user)) {
            abort(422, '需要有效套餐才能添加白名单IP');
        }

        $ip     = trim($request->input('ip', ''));
        $remark = trim($request->input('remark', ''));

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            abort(422, 'IP 地址格式不正确');
        }

        $limit = $this->getLimit($user);
        $used  = DB::table('v2_subscribe_whitelist')->where('user_id', $userId)->count();

        if ($limit !== null && $used >= $limit) {
            abort(422, "白名单已达上限（{$limit} 个），请先删除后再添加");
        }

        $exists = DB::table('v2_subscribe_whitelist')
            ->where('user_id', $userId)
            ->where('ip', $ip)
            ->exists();

        if ($exists) {
            abort(422, '该 IP 已在白名单中');
        }

        $location = \App\Services\GeoIpService::getLocation($ip);

        // 如启用大陆限制，非中国大陆IP不允许加入白名单
        if ($this->chinaOnlyEnabled) {
            $countryCode = $location['countryCode'] ?? '';
            $country     = $location['country']     ?? '';
            // 内网/API失败(countryCode为空)放行；港澳台和其他地区拒绝
            $isCn = $country === '内网' || $countryCode === '' || $countryCode === 'CN';
            if (!$isCn) {
                abort(422, '当前IP归属地为' . ($country ?: '未知') . '，仅限中国大陆IP加入白名单');
            }
        }

        DB::table('v2_subscribe_whitelist')->insert([
            'user_id'    => $userId,
            'ip'         => $ip,
            'country'    => $location['country']  ?? null,
            'province'   => $location['province'] ?? null,
            'city'        => $location['city']    ?? null,
            'isp'        => $location['isp']      ?? null,
            'remark'     => $remark ?: null,
            'created_at' => time(),
        ]);

        return response(['data' => true]);
    }

    /**
     * 删除白名单IP
     */
    public function drop(Request $request)
    {
        $userId = $request->user['id'] ?? null;
        if (!$userId) {
            abort(500, __('The user does not exist'));
        }

        $id = (int)$request->input('id');
        if (!$id) {
            abort(422, '参数错误');
        }

        $deleted = DB::table('v2_subscribe_whitelist')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->delete();

        if (!$deleted) {
            abort(422, '记录不存在');
        }

        return response(['data' => true]);
    }

    /**
     * 获取用户白名单配额上限
     * 无有效套餐返回 0；优先取用户表 device_limit，其次取套餐 device_limit，都未设置返回 null（不限数量）
     */
    private function getLimit($user): ?int
    {
        if (!$this->hasActivePlan($user)) {
            return 0;
        }

        if ($user->device_limit > 0) {
            return (int)$user->device_limit;
        }

        if (!empty($user->plan_id)) {
            $plan = DB::table('v2_plan')->where('id', $user->plan_id)->first();
            if ($plan && $plan->device_limit > 0) {
                return (int)$plan->device_limit;
            }
        }

        return null; // null 表示不限数量
    }

    /**
     * 判断用户是否持有有效套餐
     * 条件：plan_id 不为空 + 未封禁 + 未过期（expired_at 为 null 视为永久有效）
     */
    private function hasActivePlan($user): bool
    {
        if (empty($user->plan_id)) {
            return false;
        }
        if (!empty($user->banned)) {
            return false;
        }
        $expiredAt = $user->expired_at ?? null;
        return $expiredAt === null || $expiredAt > time();
    }
}
