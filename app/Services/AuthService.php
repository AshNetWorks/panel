<?php

namespace App\Services;

use App\Utils\CacheKey;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class AuthService
{
    private $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(Request $request)
    {
        $guid = Helper::guid();

        // 1) 真实 IP（多层代理识别）
        $realIp = self::getRealIp();

        // 2) 设备类型解析
        $device = self::parseDevice($request->userAgent());

        // 3) IP 归属地（失败或私网仅显示 IP）
        $ipDisplay = self::buildIpDisplay($realIp);

        $authData = JWT::encode([
            'id' => $this->user->id,
            'session' => $guid,
        ], config('app.key'), 'HS256');

        self::addSession($this->user->id, $guid, [
            'ip'        => $ipDisplay,              // ← 合成后的展示字段
            'device'    => $device,                 // ← 新增字段（仅缓存）
            'login_at'  => time(),
            'ua'        => $request->userAgent(),
            'auth_data' => $authData
        ]);

        return [
            'token'    => $this->user->token,
            'is_admin' => $this->user->is_admin,
            'auth_data'=> $authData
        ];
    }

    public static function decryptAuthData($jwt)
    {
        try {
            if (!Cache::has($jwt)) {
                $data = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
                if (!self::checkSession($data['id'], $data['session'])) return false;
                $user = User::select([
                    'id',
                    'email',
                    'is_admin',
                    'is_staff'
                ])->find($data['id']);
                if (!$user) return false;
                Cache::put($jwt, $user->toArray(), 3600);
            }
            return Cache::get($jwt);
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function checkSession($userId, $session)
    {
        $sessions = (array)Cache::get(CacheKey::get("USER_SESSIONS", $userId)) ?? [];
        if (!in_array($session, array_keys($sessions))) return false;
        return true;
    }

    private static function addSession($userId, $guid, $meta)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $userId);
        $sessions = (array)Cache::get($cacheKey, []);
        $sessions[$guid] = $meta;
        if (!Cache::put($cacheKey, $sessions)) return false;
        return true;
    }

    public function getSessions()
    {
        return (array)Cache::get(CacheKey::get("USER_SESSIONS", $this->user->id), []);
    }

    public function removeSession($sessionId)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        unset($sessions[$sessionId]);
        if (!Cache::put($cacheKey, $sessions)) return false;
        return true;
    }

    public function removeAllSession()
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        foreach ($sessions as $guid => $meta) {
            if (isset($meta['auth_data'])) {
                Cache::forget($meta['auth_data']);
            }
        }
        return Cache::forget($cacheKey);
    }

    /* =========================
     *       增强工具方法
     * ========================= */

    /**
     * 多层代理真实 IP 获取：
     * - 优先 CF-Connecting-IP
     * - 然后 X-Forwarded-For（取第一个）
     * - 然后 X-Real-IP
     * - 最后 REMOTE_ADDR
     */
    private static function getRealIp(): string
    {
        $candidates = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key])) {
                $raw = trim($_SERVER[$key]);
                // X-Forwarded-For 可能是 "client, proxy1, proxy2"
                $ip = $key === 'HTTP_X_FORWARDED_FOR'
                    ? trim(explode(',', $raw)[0])
                    : $raw;

                // 仅接受合法 IPv4/IPv6
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '127.0.0.1';
    }

    /**
     * 是否是内网/本地地址
     */
    private static function isPrivateIp(string $ip): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1') return true;

        // FILTER_FLAG_NO_PRIV_RANGE/NO_RES_RANGE 对 IPv6 支持有限，补充手动判断
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        return false;
    }

    /**
     * 构建展示用 IP 字段（合成）：
     * - 成功： "1.2.3.4（国家 城市）"
     * - 失败/内网：仅 "1.2.3.4"
     */
    private static function buildIpDisplay(string $ip): string
    {
        if (self::isPrivateIp($ip)) {
            return $ip; // B 方案：不加括号
        }

        $loc = self::getIpLocation($ip);
        if ($loc && !empty($loc['country'])) {
            $country = $loc['country'];
            $city = $loc['city'] ?? '';
            $city = $city ? (' ' . $city) : '';
            return "{$ip}（{$country}{$city}）";
        }

        return $ip; // B 方案 fallback
    }

    /**
     * 设备类型解析（非常轻量的 UA 匹配，不依赖三方库）
     */
    private static function parseDevice(?string $ua): string
    {
        $ua = $ua ?? '';

        if ($ua === '') return 'Other';
        if (stripos($ua, 'Android') !== false) return 'Android';
        if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false || stripos($ua, 'iOS') !== false) return 'iOS';
        if (stripos($ua, 'Windows') !== false) return 'Windows';
        if (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) return 'Mac';
        if (stripos($ua, 'Linux') !== false) return 'Linux';

        return 'Other';
    }

    /**
     * IP 归属地查询（ip-api.com, zh-CN）
     * - 成功返回 ['country' => '中国', 'city' => '上海']
     * - 失败返回 null
     * - 结果缓存 24 小时
     */
    private static function getIpLocation(string $ip): ?array
    {
        $cacheKey = 'IP_GEO_' . $ip;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        // ip-api.com 不支持内网 IP，提前返回
        if (self::isPrivateIp($ip)) {
            Cache::put($cacheKey, null, 86400);
            return null;
        }

        $url = "http://ip-api.com/json/{$ip}?lang=zh-CN&fields=status,country,city,message";

        $resp = self::simpleHttpGet($url, 2.5); // 2.5s 超时
        if (!$resp) {
            Cache::put($cacheKey, null, 300); // 网络失败短缓存 5 分钟
            return null;
        }

        $data = json_decode($resp, true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
            Cache::put($cacheKey, null, 3600); // 失败结果缓存 1 小时
            return null;
        }

        $result = [
            'country' => $data['country'] ?? '',
            'city'    => $data['city'] ?? '',
        ];
        Cache::put($cacheKey, $result, 86400); // 成功缓存 24 小时
        return $result;
    }

    /**
     * 轻量 GET（优先 file_get_contents，其次 curl）
     */
    private static function simpleHttpGet(string $url, float $timeoutSeconds = 3.0): ?string
    {
        // 1) 优先 file_get_contents
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => $timeoutSeconds,
                'header'  => "Accept: application/json\r\nUser-Agent: AuthService-Geo/1.0\r\n",
            ]
        ]);

        try {
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp !== false) return $resp;
        } catch (\Throwable $e) {
            // ignore
        }

        // 2) 备选 curl
        if (function_exists('curl_init')) {
            try {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)ceil($timeoutSeconds));
                curl_setopt($ch, CURLOPT_TIMEOUT, (int)ceil($timeoutSeconds));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Accept: application/json',
                    'User-Agent: AuthService-Geo/1.0'
                ]);
                $resp = curl_exec($ch);
                $err  = curl_error($ch);
                curl_close($ch);
                if ($resp !== false && !$err) return $resp;
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return null;
    }
}
