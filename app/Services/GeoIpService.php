<?php
namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader as MmdbReader;

class GeoIpService
{
    private const GEOLITE2_ASN_PATH  = 'app/geoip/GeoLite2-ASN.mmdb';
    private const GEOLITE2_CITY_PATH = 'app/geoip/GeoLite2-City.mmdb';
    private const GEOCN_PATH         = 'app/GeoCN.mmdb';

    // GeoLite2 英文国家名 → 中文（仅中国相关地区）
    public const COUNTRY_NAME_MAP = [
        'China'     => '中国',
        'Hong Kong' => '香港',
        'Macao'     => '澳门',
        'Macau'     => '澳门',
        'Taiwan'    => '台湾',
    ];

    // GeoLite2 英文省名 → 中文规范名（中国34个省级行政区）
    public const PROVINCE_NAME_MAP = [
        'Beijing'          => '北京', 'Shanghai'     => '上海',
        'Tianjin'          => '天津', 'Chongqing'    => '重庆',
        'Guangdong'        => '广东', 'Zhejiang'     => '浙江',
        'Jiangsu'          => '江苏', 'Shandong'     => '山东',
        'Henan'            => '河南', 'Sichuan'      => '四川',
        'Hubei'            => '湖北', 'Hunan'        => '湖南',
        'Anhui'            => '安徽', 'Fujian'       => '福建',
        'Hebei'            => '河北', 'Shanxi'       => '山西',
        'Shaanxi'          => '陕西', 'Liaoning'     => '辽宁',
        'Jilin'            => '吉林', 'Heilongjiang' => '黑龙江',
        'Jiangxi'          => '江西', 'Guangxi'      => '广西',
        'Yunnan'           => '云南', 'Guizhou'      => '贵州',
        'Gansu'            => '甘肃', 'Qinghai'      => '青海',
        'Hainan'           => '海南', 'Inner Mongolia' => '内蒙古',
        'Xinjiang'         => '新疆', 'Tibet'        => '西藏',
        'Ningxia'          => '宁夏',
    ];

    /**
     * 查询 IP 归属地
     *
     * 国内判定：GeoCN 命中（country='中国'），或 GeoCN 未收录但 GeoLite2 判定 countryCode=CN
     *           （主要覆盖 GeoCN 不完整的国内 IPv6）
     *
     * 国内路径（IPv4 / IPv6 统一）：
     *   位置：GeoCN 城市已知 → 直接用
     *         GeoCN 城市未知 → [GeoCN, GeoLite2-City] + [Aliyun（仅 IPv4）] → pickBest
     *   ISP：GeoCN → Aliyun（仅 IPv4）→ GeoLite2-ASN（queryAsnOnly，不重复查 City）
     *
     * 非国内路径：
     *   GeoLite2-City+ASN（已在判定阶段查过）→ 城市未知时 ip-api.com 兜底
     *
     * 结果经 normalizeChineseFields 规范化（港澳台英文字段 → 中文）后缓存 24h；全失败缓存 5min
     *
     * @param string $ip
     * @param string $appCode 阿里云 AppCode，为空时跳过
     * @return array{country:string,countryCode:string,province:string,city:string,org:string,isp:string}
     */
    public static function getLocation(string $ip, string $appCode = ''): array
    {
        // 内网 IP 直接返回
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['country' => '内网', 'countryCode' => 'CN', 'province' => '内网', 'city' => '内网', 'org' => '', 'isp' => ''];
        }

        $cacheKey = 'sub_ip_geo_' . $ip;
        $cached   = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $isIPv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (!$appCode) {
            $appCode = config('v2board.aliyun_ip_appcode', '');
        }

        // ① GeoCN（本地，中国大陆专用）
        $geocnResult = self::queryGeoCN($ip);

        // ② 国内判定：GeoCN 命中 OR GeoLite2 判定 CN（兜底国内 IPv6）
        $geoLite2Result  = null;
        $isMainlandChina = ($geocnResult['country'] === '中国');
        if (!$isMainlandChina) {
            $geoLite2Result  = self::queryGeoLite2($ip); // 非国内才查，避免重复
            $isMainlandChina = ($geoLite2Result['countryCode'] ?? '') === 'CN';
        }

        if ($isMainlandChina) {
            // ── 国内路径 ─────────────────────────────────────────────────────
            $aliyunResult   = null;
            $geocnCityKnown = ($geocnResult['country'] === '中国')
                && !empty($geocnResult['city']) && $geocnResult['city'] !== '未知';

            if ($geocnCityKnown) {
                // 城市已知：直接用 GeoCN，不再查 GeoLite2 City 库（节省开销）
                $best = $geocnResult;
            } else {
                // 城市未知：GeoLite2（懒加载，已在判定阶段查过则复用）+ Aliyun（仅 IPv4）
                $geoLite2Result ??= self::queryGeoLite2($ip);
                $candidates = array_filter([
                    $geocnResult['country'] === '中国' ? $geocnResult : null,
                    $geoLite2Result,
                ]);
                if (!$isIPv6 && $appCode) {
                    $aliyunResult = self::queryAliyun($ip, $appCode);
                    $candidates[] = $aliyunResult;
                }
                $best = self::pickBest(array_values($candidates));
            }

            // ISP 优先级：GeoCN → Aliyun（仅 IPv4）→ GeoLite2-ASN
            // 城市已知时 geoLite2Result 未查，直接用 queryAsnOnly 更轻量
            $bestIsp = $geocnResult['isp'] ?? '';
            if (empty($bestIsp) && $aliyunResult !== null) {
                $bestIsp = $aliyunResult['isp'] ?? '';
            }
            if (empty($bestIsp)) {
                $bestIsp = ($geoLite2Result !== null && !empty($geoLite2Result['isp']))
                    ? $geoLite2Result['isp']
                    : self::queryAsnOnly($ip);
            }
            if ($bestIsp !== '') {
                $best['isp'] = $bestIsp;
                $best['org'] = $bestIsp;
            }
        } else {
            // ── 非国内路径 ───────────────────────────────────────────────────
            // $geoLite2Result 已在判定阶段查过，直接复用
            $cityKnown = !empty($geoLite2Result['city']) && $geoLite2Result['city'] !== '未知';
            $best = $cityKnown
                ? $geoLite2Result
                : self::pickBest([$geoLite2Result, self::queryIpApi($ip)]);
        }

        $best = self::normalizeChineseFields($best);
        $ttl  = self::scoreResult($best) > 0 ? 86400 : 300;
        Cache::put($cacheKey, $best, $ttl);
        return $best;
    }

    // -------------------------------------------------------------------------
    // 各来源查询
    // -------------------------------------------------------------------------

    /**
     * 仅查询 GeoLite2-ASN 库，返回 ISP/org 字符串
     * 用于国内 IP 补充 GeoCN 缺少的 ISP 字段，不重复查 City 库
     */
    private static function queryAsnOnly(string $ip): string
    {
        $asnPath = storage_path(self::GEOLITE2_ASN_PATH);
        if (!file_exists($asnPath)) {
            return '';
        }
        try {
            $reader = new MmdbReader($asnPath);
            $record = $reader->get($ip);
            $reader->close();
            return $record['autonomous_system_organization'] ?? '';
        } catch (\Exception $e) {
            Log::warning('GeoLite2-ASN 查询失败', ['ip' => $ip, 'error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * 查询 GeoLite2-City + ASN 本地库
     */
    private static function queryGeoLite2(string $ip): array
    {
        $empty    = ['country' => '未知', 'countryCode' => '', 'province' => '未知', 'city' => '未知', 'org' => '', 'isp' => ''];
        $cityPath = storage_path(self::GEOLITE2_CITY_PATH);
        $asnPath  = storage_path(self::GEOLITE2_ASN_PATH);
        $result   = $empty;

        // City 库（地理位置）
        if (file_exists($cityPath)) {
            try {
                $reader = new MmdbReader($cityPath);
                $record = $reader->get($ip);
                $reader->close();

                if ($record) {
                    $countryRec   = $record['country']      ?? [];
                    $subdivisions = $record['subdivisions'] ?? [];
                    $cityRec      = $record['city']         ?? [];

                    $countryName = $countryRec['names']['zh-CN']
                        ?? ($countryRec['names']['en'] ?? '');
                    $countryCode = $countryRec['iso_code'] ?? '';
                    $province    = !empty($subdivisions)
                        ? ($subdivisions[0]['names']['zh-CN'] ?? ($subdivisions[0]['names']['en'] ?? ''))
                        : '';
                    $cityName    = $cityRec['names']['zh-CN']
                        ?? ($cityRec['names']['en'] ?? '');

                    $result = [
                        'country'     => $countryName ?: '未知',
                        'countryCode' => $countryCode,
                        'province'    => $province    ?: '未知',
                        'city'        => $cityName    ?: '未知',
                        'org'         => '',
                        'isp'         => '',
                    ];
                }
            } catch (\Exception $e) {
                Log::warning('GeoLite2-City 查询失败', ['ip' => $ip, 'error' => $e->getMessage()]);
            }
        }

        // ASN 库（ISP / 运营商）
        if (file_exists($asnPath)) {
            try {
                $reader = new MmdbReader($asnPath);
                $record = $reader->get($ip);
                $reader->close();

                if ($record) {
                    $org           = $record['autonomous_system_organization'] ?? '';
                    $result['org'] = $org;
                    $result['isp'] = $org;
                }
            } catch (\Exception $e) {
                Log::warning('GeoLite2-ASN 查询失败', ['ip' => $ip, 'error' => $e->getMessage()]);
            }
        }

        return $result;
    }

    /**
     * 查询 GeoCN.mmdb（中国大陆 IP 专用，精度更高）
     */
    private static function queryGeoCN(string $ip): array
    {
        $empty  = ['country' => '未知', 'countryCode' => '', 'province' => '未知', 'city' => '未知', 'org' => '', 'isp' => ''];
        $dbPath = storage_path(self::GEOCN_PATH);

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

            $province = $record['province']  ?? '';
            $city     = $record['city']      ?? '';
            $isp      = $record['isp']       ?? ($record['net'] ?? '');

            return [
                'country'     => '中国',
                'countryCode' => 'CN',
                'province'    => $province ?: '未知',
                'city'        => $city     ?: '未知',
                'org'         => $isp,
                'isp'         => $isp,
            ];
        } catch (\Exception $e) {
            Log::warning('GeoCN.mmdb 查询失败', ['ip' => $ip, 'error' => $e->getMessage()]);
            return $empty;
        }
    }

    /**
     * 查询阿里云 IP 定位 API（仅 IPv4）
     */
    private static function queryAliyun(string $ip, string $appCode): array
    {
        $empty = ['country' => '未知', 'countryCode' => '', 'province' => '未知', 'city' => '未知', 'org' => '', 'isp' => ''];

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
            curl_close($ch);

            $data = $response ? json_decode($response, true) : null;
            if ($data && ($data['success'] ?? false) === true && isset($data['data'])) {
                $d        = $data['data'];
                $nation   = $d['nation']   ?? '未知';
                $province = $d['province'] ?? '未知';

                if ($nation === '中国') {
                    if (mb_strpos($province, '香港') !== false)     $countryCode = 'HK';
                    elseif (mb_strpos($province, '澳门') !== false) $countryCode = 'MO';
                    elseif (mb_strpos($province, '台湾') !== false) $countryCode = 'TW';
                    else                                             $countryCode = 'CN';
                } else {
                    // 阿里云不返回非中国 ISO 代码，用 XX 占位，避免误判为大陆
                    $countryCode = 'XX';
                }

                return [
                    'country'     => $nation,
                    'countryCode' => $countryCode,
                    'province'    => $province,
                    'city'        => $d['city'] ?? '未知',
                    'org'         => $d['isp']  ?? '',
                    'isp'         => $d['isp']  ?? '',
                ];
            }
        } catch (\Exception $e) {
            // 静默失败，由 pickBest 兜底
        }

        return $empty;
    }

    /**
     * 查询 ip-api.com（IPv4 / IPv6 均支持）
     */
    private static function queryIpApi(string $ip): array
    {
        $empty = ['country' => '未知', 'countryCode' => '', 'province' => '未知', 'city' => '未知', 'org' => '', 'isp' => ''];
        $ctx   = stream_context_create(['http' => ['timeout' => 3]]);

        try {
            $response = @file_get_contents(
                "http://ip-api.com/json/{$ip}?lang=zh-CN&fields=status,country,countryCode,regionName,city,org,isp",
                false,
                $ctx
            );
            $data = $response ? json_decode($response, true) : null;
            if ($data && ($data['status'] ?? '') === 'success') {
                return [
                    'country'     => $data['country']     ?? '未知',
                    'countryCode' => $data['countryCode'] ?? '',
                    'province'    => $data['regionName']  ?? '未知',
                    'city'        => $data['city']        ?? '未知',
                    'org'         => $data['org']         ?? '',
                    'isp'         => $data['isp']         ?? '',
                ];
            }
        } catch (\Exception $e) {
            Log::error('ip-api.com 查询失败', ['ip' => $ip, 'error' => $e->getMessage()]);
        }

        return $empty;
    }

    // -------------------------------------------------------------------------
    // 中文规范化
    // -------------------------------------------------------------------------

    /**
     * 将中国相关地区（含港澳台）的英文字段规范化为中文，再入缓存/数据库
     * 只处理 countryCode 为 CN/HK/MO/TW 的记录，其他地区不动
     */
    private static function normalizeChineseFields(array $r): array
    {
        $code = $r['countryCode'] ?? '';
        if (!in_array($code, ['CN', 'HK', 'MO', 'TW'], true)) {
            return $r;
        }

        // 国家名：英文 → 中文
        $country = $r['country'] ?? '';
        if ($country && !preg_match('/\p{Han}/u', $country)) {
            $r['country'] = self::COUNTRY_NAME_MAP[$country] ?? $country;
        }

        // 省份名：英文 → 中文（去掉"省/市/区"等后缀统一后存储）
        $province = $r['province'] ?? '';
        if ($province && $province !== '未知' && !preg_match('/\p{Han}/u', $province)) {
            $r['province'] = self::PROVINCE_NAME_MAP[$province] ?? $province;
        }

        // 城市名：无全量映射表，英文城市保留原值（白名单城市比对已有 $isChinese 守卫）

        return $r;
    }

    // -------------------------------------------------------------------------
    // 评分与优选
    // -------------------------------------------------------------------------

    /**
     * 对查询结果打分（字段越完整分越高）
     * country=4, province=3, city=2, isp=1，最高 10 分
     * 地理位置字段含汉字时额外 +1，确保汉字结果优先于拼音/英文结果
     */
    private static function scoreResult(array $r): int
    {
        $score = 0;
        if (!empty($r['country'])  && $r['country']  !== '未知') {
            $score += 4;
            if (preg_match('/\p{Han}/u', $r['country']))  $score += 1;
        }
        if (!empty($r['province']) && $r['province'] !== '未知') {
            $score += 3;
            if (preg_match('/\p{Han}/u', $r['province'])) $score += 1;
        }
        if (!empty($r['city'])     && $r['city']     !== '未知') {
            $score += 2;
            if (preg_match('/\p{Han}/u', $r['city']))     $score += 1;
        }
        if (!empty($r['isp']))   $score += 1;
        return $score; // 最高 13 分
    }

    /**
     * 从候选结果中选出得分最高的
     */
    private static function pickBest(array $candidates): array
    {
        $empty     = ['country' => '未知', 'countryCode' => '', 'province' => '未知', 'city' => '未知', 'org' => '', 'isp' => ''];
        $best      = $empty;
        $bestScore = -1;

        foreach ($candidates as $candidate) {
            $score = self::scoreResult($candidate);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $candidate;
            }
        }

        return $best;
    }
}
