<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\UserCheckin;
use Carbon\Carbon;

class CleanCheckinRecords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'checkin:clean {--days=30 : 保留的天数}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清理指定天数之前的签到记录';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $days = $this->option('days');
        $cutoffDate = Carbon::now()->subDays($days)->format('Y-m-d');

        $this->info("开始清理 {$cutoffDate} 之前的签到记录...");

        $count = UserCheckin::where('checkin_date', '<', $cutoffDate)->count();

        if ($count === 0) {
            $this->info('没有需要清理的记录。');
            return 0;
        }

        $deleted = UserCheckin::where('checkin_date', '<', $cutoffDate)->delete();

        $this->info("成功清理 {$deleted} 条记录。");

        return 0;
    }
}
