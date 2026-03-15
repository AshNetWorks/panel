<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\EmbyService;

class EmbySync extends Command
{
    protected $signature = 'emby:sync
                            {--user-id= : 指定用户ID进行同步}
                            {--server-id= : 指定服务器ID进行同步}
                            {--check-changes : 只同步状态有变化的用户}
                            {--batch-size=50 : 每批处理用户数}
                            {--batch-delay=2 : 批次间隔秒数，防止API过载}
                            {--max-time=0 : 最大执行时间(秒)，0表示不限制}
                            {--health-check : 同时检查服务器健康状态}';

    protected $description = '同步 Emby 用户状态和到期时间（支持分批处理）';

    private int $startTime;
    private int $maxExecutionTime;

    public function __construct(private EmbyService $embyService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!config('emby.sync.enabled', true)) {
            $this->info('Emby 同步功能已禁用');
            return 0;
        }

        $this->startTime = time();
        $this->maxExecutionTime = (int)$this->option('max-time') ?: (int)config('emby.sync.max_execution_time', 300);

        $this->info('开始同步 Emby 用户状态...');
        $this->info("配置: 批大小={$this->option('batch-size')}, 批间隔={$this->option('batch-delay')}秒, 最大时间={$this->maxExecutionTime}秒");

        try {
            if ($this->option('health-check')) {
                $this->performHealthCheck();
            }

            $this->performUserSync();
            $this->syncServerUserCounts();
        } catch (\Throwable $e) {
            $this->error('同步过程中发生错误: ' . $e->getMessage());
            Log::error('EmbySync error', ['error' => $e]);
            return 1;
        }

        $elapsed = time() - $this->startTime;
        $this->info("总耗时: {$elapsed} 秒");

        return 0;
    }

    /**
     * 检查是否超时
     */
    private function isTimeoutReached(): bool
    {
        if ($this->maxExecutionTime <= 0) {
            return false;
        }
        return (time() - $this->startTime) >= $this->maxExecutionTime;
    }

    /**
     * 执行健康检查
     */
    private function performHealthCheck(): void
    {
        $this->info('检查服务器健康状态...');
        $serverId = $this->option('server-id');

        if (!method_exists($this->embyService, 'checkServerHealth')) {
            $this->warn('checkServerHealth 方法未实现，跳过健康检查');
            $this->newLine();
            return;
        }

        $results = $this->embyService->checkServerHealth($serverId);

        foreach ($results as $result) {
            if (($result['status'] ?? '') === 'online') {
                $this->line("✓ {$result['server_name']}: 在线");
            } else {
                $this->line("✗ {$result['server_name']}: 离线 - {$result['error']}");
            }
        }

        $this->newLine();
    }

    /**
     * 执行用户同步（分批处理，防止超时）
     */
    private function performUserSync(): void
    {
        $userId       = $this->option('user-id');
        $serverId     = $this->option('server-id');
        $checkChanges = $this->option('check-changes');
        $batchSize    = max(1, (int)$this->option('batch-size'));
        $batchDelay   = max(0, (int)$this->option('batch-delay'));

        $query = DB::table('v2_user as u')
            ->join('v2_emby_users as eu', 'u.id', '=', 'eu.user_id')
            ->whereNotNull('u.plan_id')
            ->whereNotNull('u.expired_at');

        if ($userId) {
            $query->where('u.id', $userId);
        }

        if ($serverId) {
            $query->where('eu.emby_server_id', $serverId);
        }

        $userIds = $query->pluck('u.id')->unique()->toArray();

        if (empty($userIds)) {
            $this->info('没有需要同步的用户');
            return;
        }

        $total = count($userIds);
        $this->info("发现 {$total} 个用户需要同步");

        // 如果开启了 check-changes，先过滤出有变化的用户
        if ($checkChanges) {
            $this->info('正在检测有变化的用户...');
            $changedUserIds = [];
            foreach (array_chunk($userIds, 200) as $chunk) {
                foreach ($chunk as $uid) {
                    if ($this->hasUserChanged((int)$uid)) {
                        $changedUserIds[] = $uid;
                    }
                }
            }
            $userIds = $changedUserIds;
            $changedCount = count($userIds);
            $this->info("实际需要同步的用户: {$changedCount} 个（已过滤无变化用户）");

            if (empty($userIds)) {
                $this->info('所有用户状态均为最新，无需同步');
                return;
            }
        }

        $batches      = array_chunk($userIds, $batchSize);
        $totalBatches = count($batches);
        $totalSuccess = 0;
        $totalFailed  = 0;
        $totalSkipped = 0;
        $allErrors    = [];

        $this->info("分 {$totalBatches} 批处理，每批 {$batchSize} 个用户");
        $this->newLine();

        $progressBar = $this->output->createProgressBar(count($userIds));
        $progressBar->start();

        foreach ($batches as $batchIndex => $batch) {
            // 检查是否超时
            if ($this->isTimeoutReached()) {
                $progressBar->finish();
                $this->newLine();
                $remaining = count($userIds) - ($batchIndex * $batchSize);
                $this->warn("已达最大执行时间 {$this->maxExecutionTime} 秒，剩余 {$remaining} 个用户将在下次同步");
                $totalSkipped = $remaining;
                break;
            }

            $batchCount = count($batch);

            // 调用服务进行同步
            $result = $this->embyService->batchSyncUserExpiration($batch);

            $totalSuccess += (int)($result['success'] ?? 0);
            $totalFailed  += (int)($result['failed'] ?? 0);
            if (!empty($result['errors'])) {
                $allErrors = array_merge($allErrors, array_slice($result['errors'], 0, 5)); // 只保留前5个错误
            }

            $progressBar->advance($batchCount);

            // 批次间隔，防止 API 过载
            if ($batchDelay > 0 && $batchIndex < $totalBatches - 1) {
                sleep($batchDelay);
            }
        }

        $progressBar->finish();
        $this->newLine();
        $this->newLine();

        // 输出统计
        $this->info("同步完成！成功: {$totalSuccess}，失败: {$totalFailed}" . ($totalSkipped > 0 ? "，跳过: {$totalSkipped}" : ""));

        if (!empty($allErrors)) {
            $this->newLine();
            $this->error('部分同步失败（最多显示10条）：');
            foreach (array_slice($allErrors, 0, 10) as $error) {
                $this->line("  - {$error}");
            }
            if (count($allErrors) > 10) {
                $this->line('  ... 还有更多错误，请查看日志');
            }
        }

        // 记录日志
        if (config('emby.logging.enabled', true)) {
            Log::info('Emby 同步完成', [
                'success' => $totalSuccess,
                'failed'  => $totalFailed,
                'skipped' => $totalSkipped,
                'elapsed' => time() - $this->startTime,
            ]);
        }
    }

    /**
     * 过滤有变化的用户
     */
    private function filterChangedUsers(array $userIds): array
    {
        $changed = [];
        foreach ($userIds as $uid) {
            if ($this->hasUserChanged((int)$uid)) {
                $changed[] = (int)$uid;
            }
        }
        return $changed;
    }

    /**
     * 检查用户是否有变化（对齐实际库结构）
     * - v2_user.expired_at: 时间戳(int)
     * - v2_emby_users.expired_at: datetime 字符串
     * - 用户启用条件：plan_id 存在 && 未过期 && banned == 0
     */
    private function hasUserChanged(int $userId): bool
    {
        $user = DB::table('v2_user')
            ->where('id', $userId)
            ->select(['id', 'plan_id', 'expired_at', 'banned'])
            ->first();

        if (!$user) {
            return false;
        }

        $latest = DB::table('v2_emby_users')
            ->where('user_id', $userId)
            ->orderBy('updated_at', 'desc')
            ->first();

        if (!$latest) {
            return true; // 没有 Emby 记录，需要同步
        }

        $userTs = (int)($user->expired_at ?? 0);
        $embyTs = $latest->expired_at ? strtotime($latest->expired_at) : 0;

        // 到期时间是否变化
        if ($embyTs !== $userTs) {
            return true;
        }

        // 正确的启用判定（不使用 user->status）
        $shouldEnable = $user->plan_id &&
                        $userTs > time() &&
                        ((int)($user->banned ?? 0) === 0);

        if ((int)$latest->status !== (int)$shouldEnable) {
            return true;
        }

        return false;
    }

    /**
     * 同步服务器用户数量
     */
    private function syncServerUserCounts(): void
    {
        if (!method_exists($this->embyService, 'syncServerUserCounts')) {
            $this->warn('syncServerUserCounts 方法未实现，跳过服务器用户数同步');
            return;
        }

        $this->info('同步服务器用户数量...');
        $serverId = $this->option('server-id');
        $this->embyService->syncServerUserCounts($serverId);
        $this->info('服务器用户数量同步完成');
    }
}