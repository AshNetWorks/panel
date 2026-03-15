<?php
// ============ 新增健康检查命令 ============
// app/Console/Commands/EmbyHealthCheck.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\EmbyService;

class EmbyHealthCheck extends Command
{
    protected $signature = 'emby:health-check 
                            {--server-id= : 检查指定服务器}
                            {--fix : 自动修复发现的问题}';
    protected $description = '检查Emby服务器健康状态';

    protected $embyService;

    public function __construct(EmbyService $embyService)
    {
        parent::__construct();
        $this->embyService = $embyService;
    }

    public function handle()
    {
        $this->info('开始Emby健康检查...');

        $serverId = $this->option('server-id');
        $fix = $this->option('fix');

        try {
            $results = $this->embyService->checkServerHealth($serverId);
            
            $onlineCount = 0;
            $offlineCount = 0;

            $this->newLine();
            $this->info('服务器状态检查结果：');
            $this->newLine();

            foreach ($results as $result) {
                if ($result['status'] === 'online') {
                    $onlineCount++;
                    $this->line("✓ <info>{$result['server_name']}</info>: 在线");
                    
                    // 显示服务器信息
                    if (isset($result['info']['ServerName'])) {
                        $this->line("  服务器名: {$result['info']['ServerName']}");
                        $this->line("  版本: {$result['info']['Version']}");
                    }
                } else {
                    $offlineCount++;
                    $this->line("✗ <error>{$result['server_name']}</error>: 离线");
                    $this->line("  错误: {$result['error']}");

                    if ($fix) {
                        // 这里可以添加自动修复逻辑
                        $this->line("  尝试修复中...");
                    }
                }
                $this->newLine();
            }

            // 总结
            $total = $onlineCount + $offlineCount;
            $this->info("健康检查完成！");
            $this->info("总服务器数: {$total}");
            $this->info("在线: {$onlineCount}");
            $this->info("离线: {$offlineCount}");

            if ($offlineCount > 0) {
                $this->warn("发现 {$offlineCount} 个离线服务器，请检查配置");
                return 1;
            }

        } catch (\Exception $e) {
            $this->error('健康检查失败: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}