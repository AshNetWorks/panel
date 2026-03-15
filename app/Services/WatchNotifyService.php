<?php

namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * WatchNotifyService
 *
 * 监控名单存储在 storage/app/watch_list.json，无需数据库。
 * 文件格式：{ "user_id": { "email", "note", "added_by", "created_at" }, ... }
 */
class WatchNotifyService
{
    private static string $path  = '';
    private static ?array $cache = null;   // 同一进程内复用，避免重复读文件

    private static function filePath(): string
    {
        if (!self::$path) {
            self::$path = storage_path('app/watch_list.json');
        }
        return self::$path;
    }

    // ──────────────────────────────────────────────
    // 文件读写
    // ──────────────────────────────────────────────

    private static function read(): array
    {
        if (self::$cache !== null) return self::$cache;
        $path = self::filePath();
        if (!file_exists($path)) return self::$cache = [];
        $data = json_decode(file_get_contents($path), true);
        return self::$cache = (is_array($data) ? $data : []);
    }

    private static function write(array $data): void
    {
        $path = self::filePath();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        self::$cache = $data;   // 同步更新缓存
    }

    // ──────────────────────────────────────────────
    // 增删查
    // ──────────────────────────────────────────────

    public static function addUser(int $userId, string $email, string $note, int $addedBy): void
    {
        $data = self::read();
        $data[(string)$userId] = [
            'email'      => $email,
            'note'       => $note,
            'added_by'   => $addedBy,
            'created_at' => time(),
        ];
        self::write($data);
    }

    public static function removeUser(int $userId): bool
    {
        $data = self::read();
        if (!isset($data[(string)$userId])) return false;
        unset($data[(string)$userId]);
        self::write($data);
        return true;
    }

    /** 返回监控记录（含 email / note），不在则返回 null */
    public static function isWatched(int $userId): ?array
    {
        $data = self::read();
        return $data[(string)$userId] ?? null;
    }

    /** 返回全部监控记录，key 为 user_id 字符串 */
    public static function getList(): array
    {
        return self::read();
    }

    // ──────────────────────────────────────────────
    // IP 匹配：查找历史记录中使用过该 IP 的被监控用户
    // ──────────────────────────────────────────────

    /**
     * @return array{0: array, 1: User}|null  [监控记录, 被监控用户模型]
     */
    public static function getWatchedUserByIp(string $ip): ?array
    {
        $data = self::read();
        if (empty($data)) return null;

        $watchedUserIds = array_map('intval', array_keys($data));

        $log = DB::table('v2_subscribe_pull_log')
            ->whereIn('user_id', $watchedUserIds)
            ->where('ip', $ip)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$log) return null;

        $entry       = $data[(string)$log->user_id] ?? null;
        $watchedUser = User::find($log->user_id);

        return ($entry && $watchedUser) ? [$entry, $watchedUser] : null;
    }

    // ──────────────────────────────────────────────
    // 查询被监控用户的历史 IP
    // ──────────────────────────────────────────────

    /**
     * 批量查询被监控用户拉取过订阅的 IP 列表（按最后出现时间倒序）
     *
     * @param  int[] $userIds
     * @return array  [ user_id => [ ['ip','country','city','last_seen'], ... ], ... ]
     */
    public static function getWatchedUsersIps(array $userIds): array
    {
        if (empty($userIds)) return [];

        $rows = DB::table('v2_subscribe_pull_log')
            ->whereIn('user_id', $userIds)
            ->select('user_id', 'ip', 'country', 'city', DB::raw('MAX(created_at) as last_seen'))
            ->groupBy('user_id', 'ip', 'country', 'city')
            ->orderByRaw('user_id, last_seen DESC')
            ->get()
            ->groupBy('user_id');

        $result = [];
        foreach ($rows as $uid => $entries) {
            $result[(int)$uid] = $entries->map(fn($e) => [
                'ip'        => $e->ip,
                'country'   => $e->country ?? '',
                'city'      => $e->city    ?? '',
                'last_seen' => $e->last_seen,
            ])->values()->toArray();
        }
        return $result;
    }

    // ──────────────────────────────────────────────
    // IP 关联扫描
    // ──────────────────────────────────────────────

    /**
     * 扫描监控名单中每个用户曾用过的 IP，找出与其共享同一 IP 的其他账号。
     *
     * 返回结构：
     * [
     *   watched_user_id => [
     *     'email'     => '...',
     *     'note'      => '...',
     *     'ip_groups' => [
     *       'ip' => [ ['id'=>..., 'email'=>'...'], ... ],
     *       ...
     *     ]
     *   ],
     *   ...
     * ]
     */
    public static function scanSharedIps(): array
    {
        $data = self::read();
        if (empty($data)) return [];

        $watchedUserIds = array_map('intval', array_keys($data));

        // ① 被监控用户历史上使用过的所有 IP
        $watchedLogs = DB::table('v2_subscribe_pull_log')
            ->whereIn('user_id', $watchedUserIds)
            ->select('user_id', 'ip')
            ->distinct()
            ->get();

        if ($watchedLogs->isEmpty()) return [];

        $allIps = $watchedLogs->pluck('ip')->unique()->values()->toArray();

        // ② 使用过上述 IP 的其他账号（排除被监控用户自身）
        $sharedLogs = DB::table('v2_subscribe_pull_log')
            ->whereIn('ip', $allIps)
            ->whereNotIn('user_id', $watchedUserIds)
            ->select('ip', 'user_id')
            ->distinct()
            ->get()
            ->groupBy('ip');

        if ($sharedLogs->isEmpty()) return [];

        // ③ 批量查出其他账号的邮箱
        $otherUserIds = $sharedLogs->flatten()->pluck('user_id')->unique()->toArray();
        $otherUsers   = User::whereIn('id', $otherUserIds)
            ->select('id', 'email')
            ->get()
            ->keyBy('id');

        // ④ 按被监控用户分组整理结果
        $results = [];
        foreach ($watchedLogs as $log) {
            $uid = $log->user_id;
            $ip  = $log->ip;

            if (!isset($sharedLogs[$ip])) continue;

            if (!isset($results[$uid])) {
                $entry = $data[(string)$uid] ?? null;
                if (!$entry) continue;
                $results[$uid] = [
                    'email'     => $entry['email'],
                    'note'      => $entry['note'] ?? '',
                    'ip_groups' => [],
                ];
            }

            $users = [];
            foreach ($sharedLogs[$ip] as $sl) {
                $u = $otherUsers[$sl->user_id] ?? null;
                if ($u) $users[] = ['id' => $u->id, 'email' => $u->email];
            }

            if (!empty($users)) {
                $results[$uid]['ip_groups'][$ip] = $users;
            }
        }

        return $results;
    }

    // ──────────────────────────────────────────────
    // 管理员通知
    // ──────────────────────────────────────────────

    public static function getAdminTelegramIds(): array
    {
        return User::where('is_admin', 1)
            ->whereNotNull('telegram_id')
            ->pluck('telegram_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
    }

    public static function notifyAdmins(string $message): void
    {
        if (!config('v2board.telegram_bot_enable', 0)) return;
        foreach (self::getAdminTelegramIds() as $id) {
            SendTelegramJob::dispatch($id, $message);
        }
    }
}
