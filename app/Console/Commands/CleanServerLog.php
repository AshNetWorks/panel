<?php

namespace App\Console\Commands;

use App\Models\ServerLog;
use Illuminate\Console\Command;

class CleanServerLog extends Command
{
    protected $signature = 'serverlog:clean {--days=30 : 保留的天数}';

    protected $description = '清理指定天数之前的节点使用量日志 (v2_server_log)';

    public function handle()
    {
        $days = (int) $this->option('days');
        $cutoff = strtotime("-{$days} days", strtotime(date('Y-m-d')));

        $this->info("开始清理 " . date('Y-m-d', $cutoff) . " 之前的节点日志...");

        $count = ServerLog::where('log_at', '<', $cutoff)->count();

        if ($count === 0) {
            $this->info('没有需要清理的记录。');
            return 0;
        }

        $deleted = ServerLog::where('log_at', '<', $cutoff)->delete();

        $this->info("成功清理 {$deleted} 条节点日志记录。");

        return 0;
    }
}
