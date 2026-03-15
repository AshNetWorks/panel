<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CheckSubscriptionService extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscription:check-fix';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '检查并修复订阅服务相关问题';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('🔍 开始检查订阅服务配置...');
        
        $issues = [];
        $fixes = [];

        // 1. 检查数据库表
        $this->checkDatabaseTables($issues, $fixes);
        
        // 2. 检查必要的类文件
        $this->checkRequiredClasses($issues, $fixes);
        
        // 3. 检查配置项
        $this->checkConfigurations($issues, $fixes);
        
        // 4. 检查目录权限
        $this->checkDirectoryPermissions($issues, $fixes);

        // 显示检查结果
        if (empty($issues)) {
            $this->info('✅ 所有检查项都正常！');
            return 0;
        }

        $this->error('❌ 发现以下问题：');
        foreach ($issues as $issue) {
            $this->line("  • {$issue}");
        }

        if (!empty($fixes)) {
            $this->info("\n🔧 建议修复方案：");
            foreach ($fixes as $fix) {
                $this->line("  • {$fix}");
            }
        }

        if ($this->confirm('是否尝试自动修复可修复的问题？')) {
            $this->attemptAutoFix();
        }

        return 1;
    }

    /**
     * 检查数据库表
     */
    private function checkDatabaseTables(&$issues, &$fixes)
    {
        $this->line('检查数据库表...');

        // 检查订阅拉取日志表
        if (!Schema::hasTable('v2_subscribe_pull_log')) {
            $issues[] = '缺少 v2_subscribe_pull_log 表';
            $fixes[] = '运行迁移: php artisan migrate';
        }

        // 检查用户表字段
        if (Schema::hasTable('v2_user')) {
            if (!Schema::hasColumn('v2_user', 'telegram_subscribe_notify')) {
                $issues[] = 'v2_user 表缺少 telegram_subscribe_notify 字段';
                $fixes[] = '运行用户通知设置迁移';
            }
        } else {
            $issues[] = '缺少 v2_user 表';
        }
    }

    /**
     * 检查必要的类文件
     */
    private function checkRequiredClasses(&$issues, &$fixes)
    {
        $this->line('检查必要的类文件...');

        $requiredClasses = [
            'App\Jobs\SendTelegramJob' => 'app/Jobs/SendTelegramJob.php',
            'App\Services\TelegramService' => 'app/Services/TelegramService.php',
            'App\Services\UserService' => 'app/Services/UserService.php',
            'App\Services\ServerService' => 'app/Services/ServerService.php',
        ];

        foreach ($requiredClasses as $class => $path) {
            if (!class_exists($class)) {
                $issues[] = "缺少类文件: {$class}";
                $fixes[] = "创建文件: {$path}";
            }
        }

        // 检查协议目录
        $protocolsPath = app_path('Protocols');
        if (!File::isDirectory($protocolsPath)) {
            $issues[] = 'Protocols 目录不存在';
            $fixes[] = "创建目录: {$protocolsPath}";
        }
    }

    /**
     * 检查配置项
     */
    private function checkConfigurations(&$issues, &$fixes)
    {
        $this->line('检查配置项...');

        $requiredConfigs = [
            'v2board.telegram_bot_enable' => 'Telegram Bot 开关',
            'v2board.telegram_bot_token' => 'Telegram Bot Token',
            'v2board.show_info_to_server_enable' => '服务器信息显示开关',
        ];

        foreach ($requiredConfigs as $key => $description) {
            if (config($key) === null) {
                $issues[] = "缺少配置项: {$key} ({$description})";
                $fixes[] = "在配置文件中添加: {$key}";
            }
        }
    }

    /**
     * 检查目录权限
     */
    private function checkDirectoryPermissions(&$issues, &$fixes)
    {
        $this->line('检查目录权限...');

        $directories = [
            storage_path('logs'),
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
        ];

        foreach ($directories as $dir) {
            if (!File::isWritable($dir)) {
                $issues[] = "目录不可写: {$dir}";
                $fixes[] = "设置权限: chmod 755 {$dir}";
            }
        }
    }

    /**
     * 尝试自动修复
     */
    private function attemptAutoFix()
    {
        $this->info('🔧 开始自动修复...');

        // 创建必要的目录
        $this->createDirectories();
        
        // 创建基础的 TelegramService（如果不存在）
        $this->createBasicTelegramService();

        $this->info('✅ 自动修复完成！');
        $this->warn('⚠️  请手动检查以下项目：');
        $this->line('  • 运行数据库迁移');
        $this->line('  • 配置 Telegram Bot Token');
        $this->line('  • 检查队列配置');
    }

    /**
     * 创建必要的目录
     */
    private function createDirectories()
    {
        $directories = [
            app_path('Jobs'),
            app_path('Services'),
            app_path('Protocols'),
        ];

        foreach ($directories as $dir) {
            if (!File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
                $this->line("✅ 创建目录: {$dir}");
            }
        }
    }

    /**
     * 创建基础的 TelegramService
     */
    private function createBasicTelegramService()
    {
        $servicePath = app_path('Services/TelegramService.php');
        
        if (!File::exists($servicePath)) {
            $serviceContent = '<?php

namespace App\Services;

class TelegramService
{
    public function sendMessage($telegramId, $message, $parseMode = "Markdown")
    {
        $botToken = config("v2board.telegram_bot_token");
        if (!$botToken) {
            return false;
        }

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $data = [
            "chat_id" => $telegramId,
            "text" => $message,
            "parse_mode" => $parseMode
        ];

        $context = stream_context_create([
            "http" => [
                "method" => "POST",
                "header" => "Content-Type: application/x-www-form-urlencoded\r\n",
                "content" => http_build_query($data),
                "timeout" => 10
            ]
        ]);

        $result = @file_get_contents($url, false, $context);
        return $result !== false;
    }
}';

            File::put($servicePath, $serviceContent);
            $this->line("✅ 创建基础 TelegramService");
        }
    }
}