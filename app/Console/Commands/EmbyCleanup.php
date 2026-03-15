<?php

// ============ 更新的清理过期账号命令 ============
// app/Console/Commands/EmbyCleanup.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\EmbyService;

class EmbyCleanup extends Command
{
    protected $signature = 'emby:cleanup 
                            {--days=30 : 删除多少天前过期的账号}
                            {--dry-run : 仅显示将要删除的账号，不实际执行}
                            {--force : 强制执行，不需要确认}';
    protected $description = '清理过期的Emby账号';

    protected $embyService;

    public function __construct(EmbyService $embyService)
    {
        parent::__construct();
        $this->embyService = $embyService;
    }

    public function handle()
    {
        if (!config('emby.cleanup.enabled', true)) {
            $this->info('Emby清理功能已禁用');
            return 0;
        }

        $days = (int)$this->option('days');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info("开始清理 {$days} 天前过期的Emby账号...");

        try {
            if ($dryRun) {
                $this->dryRunCleanup($days);
            } else {
                $this->performCleanup($days, $force);
            }
        } catch (\Exception $e) {
            $this->error('清理过程中发生错误: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * 模拟运行清理
     */
    private function dryRunCleanup($days)
    {
        $cutoffDate = now()->subDays($days);
        
        $expiredUsers = \DB::table('v2_emby_users as eu')
            ->leftJoin('v2_user as u', 'eu.user_id', '=', 'u.id')
            ->leftJoin('v2_emby_servers as es', 'eu.emby_server_id', '=', 'es.id')
            ->where('eu.expired_at', '<', $cutoffDate)
            ->where('eu.status', 0)
            ->select([
                'eu.id',
                'eu.username',
                'eu.expired_at',
                'u.email as user_email',
                'es.name as server_name'
            ])
            ->get();

        if ($expiredUsers->isEmpty()) {
            $this->info('没有找到需要清理的过期账号');
            return;
        }

        $this->info("找到 {$expiredUsers->count()} 个需要清理的过期账号：");
        $this->newLine();

        $headers = ['ID', '用户邮箱', 'Emby用户名', '服务器', '过期时间'];
        $rows = [];

        foreach ($expiredUsers as $user) {
            $rows[] = [
                $user->id,
                $user->user_email ?: 'N/A',
                $user->username,
                $user->server_name,
                $user->expired_at
            ];
        }

        $this->table($headers, $rows);
        $this->newLine();
        $this->info('使用 --dry-run=false 来实际执行清理操作');
    }

    /**
     * 执行清理
     */
    private function performCleanup($days, $force)
    {
        if (!$force && config('emby.cleanup.confirm_required', true)) {
            if (!$this->confirm("确定要清理 {$days} 天前过期的账号吗？此操作不可撤销。")) {
                $this->info('操作已取消');
                return;
            }
        }

        $result = $this->embyService->cleanupExpiredAccounts($days);

        $this->info("清理完成！");
        $this->info("总计: {$result['total']} 个账号");
        $this->info("成功删除: {$result['success']} 个");
        $this->info("删除失败: {$result['failed']} 个");

        if (!empty($result['errors'])) {
            $this->newLine();
            $this->error('以下账号删除失败：');
            foreach ($result['errors'] as $error) {
                $this->line("  - {$error}");
            }
        }

        // 记录到日志文件
        if (config('emby.logging.enabled', true)) {
            $logMessage = sprintf(
                'Emby清理完成 - 总计:%d 成功:%d 失败:%d',
                $result['total'],
                $result['success'],
                $result['failed']
            );
            \Log::channel('single')->info($logMessage, $result);
        }
    }
}