<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Str;

class UpdateUserUuids extends Command
{
    protected $signature = 'user:update-uuids 
                          {--user-ids= : 指定用户ID，用逗号分隔}
                          {--all : 更新所有用户}
                          {--status=1 : 指定用户状态}';

    protected $description = '批量更新用户UUID';

    public function handle()
    {
        $this->info('开始更新用户UUID...');

        $query = User::query();

        // 根据参数筛选用户
        if ($this->option('user-ids')) {
            $userIds = explode(',', $this->option('user-ids'));
            $query->whereIn('id', $userIds);
        } elseif ($this->option('all')) {
            // 更新所有用户
        } else {
            $query->where('status', $this->option('status'));
        }

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->error('没有找到匹配的用户');
            return;
        }

        $this->info("找到 {$users->count()} 个用户需要更新");

        $bar = $this->output->createProgressBar($users->count());
        $bar->start();

        $updated = 0;
        foreach ($users as $user) {
            $oldUuid = $user->uuid;
            $user->uuid = Str::uuid();
            
            if ($user->save()) {
                $updated++;
                $this->line("\n用户 {$user->id}: {$oldUuid} -> {$user->uuid}");
            }
            
            $bar->advance();
        }

        $bar->finish();
        $this->info("\n成功更新 {$updated} 个用户的UUID");
    }
}