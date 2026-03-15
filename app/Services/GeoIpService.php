<?php
namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader as MmdbReader;

class GeoIpService
{
    /**
     * 查询IP归属地
     * IPv6：直接走本地 GeoCN.mmdb 离线库
     * IPv4：主查询阿里云 → 失败则走离线库 → 最终备用 ip-api.com
     * 结果缓存 24 小时；全部失败则缓存 5 分钟
     *
     * @param string $ip
     * @param string $appCode 阿里云 AppCode，为空时跳过主查询
     * @return array{country:string,countryCode:string,province:string,city:string,org:string,isp:string}
     */
    public static function getLocation(string $ip, string $appCode = ''): array
    {
        // 内网IP直接返回，不走缓存和API
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['country' => '内网', 'countryCode' => 'CN', 'province' => '内网', 'city' => '内网', 'org' => '', 'isp' => ''];
        }

        $cacheKey = 'sub_ip_geo_' . $ip;
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $empty   = ['country' => '未知', 'countryCode' => '', 'province' => '未知', 'city' => '未知', 'org' => '', 'isp' => ''];
        $isIPv6  = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        // IPv6：直接走离线库，不请求在线 API（IPv6 在线定位不准）
        if ($isIPv6) {
            $result = self::queryMmdb($ip);
            $ttl    = $result['country'] !== '未知' ? 86400 : 300;
            Cache::put($cacheKey, $result, $ttl);
            return $result;
        }

        // IPv4 主查询：阿里云（AppCode 优先用传入值，其次读 v2board 配置）
        if (!$appCode) {
            $appCode = config('v2board.aliyun_ip_appcode', '');
        }
        if ($appCode) {
            try {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => 'https://kzipglobal.market.alicloudapi.com/api/ip/query',
                    CURLOPT_CUSTOMREQUEST  => 'POST',
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: APPCODE ' . $appCode,
                        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                    ],
                    CURLOPT_POSTFIELDS     => 'ip=' . urlencode($ip),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER         => false,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_TIMEOUT        => 3,
                ]);
                $response = curl_exec($ch);
                $data = $response ? json_decode($response, true) : null;
                if ($data && ($data['success'] ?? false) === true && isset($data['data'])) {
                    $d        = $data['data'];
                    $nation   = $d['nation']   ?? '未知';
                    $province = $d['province'] ?? '未知';
                    // 阿里云对港澳台也返回 nation=中国，需通过 province 进一步区分
                    if ($nation === '中国') {
                        if (mb_strpos($province, '香港') !== false) {
                            $countryCode = 'HK';
                        } elseif (mb_strpos($province, '澳门') !== false) {
                            $countryCode = 'MO';
                        } elseif (mb_strpos($province, '台湾') !== false) {
                            $countryCode = 'TW';
                        } else {
                            $countryCode = 'CN';
                        }
                    } else {
                        // 阿里云不返回非中国国家的 ISO 代码，用 'XX' 占位
                        // 防止 isMainlandChinaIp 的 fail-open 逻辑误判为大陆
                        $countryCode = 'XX';
                    }
                    $result = [
                        'country'     => $nation,
                        'countryCode' => $countryCode,
                        'province'    => $province,
                        'city'        => $d['city'] ?? '未知',
                        'org'         => $d['isp'] ?? '',
                        'isp'         => $d['isp'] ?? '',
                    ];
                    Cache::put($cacheKey, $result, 86400);
                    return $result;
                }
            } catch (\Exception $e) {
                // 主查询失败，继续尝试离线库
            }
        }

        // IPv4 备用1：本地离线库
        $mmdbResult = self::queryMmdb($ip);
        if ($mmdbResult['country'] !== '未知') {
            Cache::put($cacheKey, $mmdbResult, 86400);
            return $mmdbResult;
        }

        // IPv4 备用2：ip-api.com
        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        try {
            $response = @file_get_contents(
                "http://ip-api.com/json/{$ip}?lang=zh-CN&fields=status,country,countryCode,regionName,city,org,isp",
                false,
                $ctx
            );
            $data = $response ? json_decode($response, true) : null;
            if ($data && ($data['status'] ?? '') === 'success') {
                $result = [
                    'country'     => $data['country']     ?? '未知',
                    'countryCode' => $data['countryCode'] ?? '',
                    'province'    => $data['regionName']  ?? '未知',
                    'city'        => $data['city']         ?? '未知',
                    'org'         => $data['org']          ?? '',
                    'isp'         => $data['isp']          ?? '',
                ];
                Cache::put($cacheKey, $result, 86400);
                return $result;
            }
        } catch (\Exception $e) {
            Log::error('IP定位失败(所有来源均不可用)', ['ip' => $ip, 'error' => $e->getMessage()]);
        }

        // 全部失败，短缓存 5 分钟
        Cache::put($cacheKey, $empty, 300);
        return $empty;
    }

    /**
     * 查询本地 GeoCN.mmdb 离线库
     * 返回格式与 getLocation 一致；查不到时返回 country='未知'
     */
    private static function queryMmdb(string $ip): array
    {
        $empty  = ['country' => '未知', 'countryCode' => '', 'province' => '未知', 'city' => '未知', 'districts' => '', 'org' => '', 'isp' => ''];
        $dbPath = storage_path('app/GeoCN.mmdb');

        if (!file_exists($dbPath)) {
            return $empty;
        }

        try {
            $reader = new MmdbReader($dbPath);
            $record = $reader->get($ip);
            $reader->close();

            if (!$record) {
                return $empty;
            }

            // GeoCN.mmdb 字段：province / city / districts / isp / net
            $province  = $record['province']  ?? '未知';
            $city      = $record['city']      ?? '未知';
            $districts = $record['districts'] ?? '';
            $isp       = $record['isp']       ?? ($record['net'] ?? '');

            // GeoCN 只收录中国大陆 IP，查到即为 CN
            return [
                'country'     => '中国',
                'countryCode' => 'CN',
                'province'    => $province  ?: '未知',
                'city'        => $city      ?: '未知',
                'districts'   => $districts,
                'org'         => $isp,
                'isp'         => $isp,
            ];
        } catch (\Exception $e) {
            Log::warning('GeoCN.mmdb 查询失败', ['ip' => $ip, 'error' => $e->getMessage()]);
            return $empty;
        }
    }
}
