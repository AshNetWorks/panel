<?php

namespace App\Console\Commands;

use App\Models\ServerLog;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class CleanServerLog extends Command
{
    protected $signature = 'serverlog:clean {--days=30 : 保留的天数}';

    protected $description = '清理指定天数之前的节点使用量日志 (v2_server_log)';

    public function handle()
    {
        $days   = (int) $this->option('days');
        $cutoff = strtotime("-{$days} days", strtotime(date('Y-m-d')));

        $count = ServerLog::where('log_at', '<', $cutoff)->count();

        if ($count === 0) {
            $this->info('没有需要清理的记录。');
            return 0;
        }

        $deleted = ServerLog::where('log_at', '<', $cutoff)->delete();

        $this->info("成功清理 {$deleted} 条节点日志记录。");

        $message = "🧹 节点日志清理完成\n" .
                   "清理周期：保留最近 {$days} 天\n" .
                   "清理截止：" . date('Y-m-d', $cutoff) . "\n" .
                   "删除记录：{$deleted} 条";

        try {
            (new TelegramService())->sendMessageWithAdmin($message);
        } catch (\Throwable $e) {
            $this->warn('TG 通知发送失败：' . $e->getMessage());
        }

        return 0;
    }
}
