<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TelegramService;

class DailyTrafficNotification extends Command
{
    protected $signature = 'telegram:daily-traffic';
    protected $description = '发送每日流量使用情况通知给绑定Telegram的用户';

    public function handle()
    {
        $this->info('开始发送每日流量通知...');
        
        $telegramService = new TelegramService();
        $result = $telegramService->sendDailyTrafficNotification();
        
        $this->info("发送完成统计:");
        $this->info("总用户数: {$result['total']}");
        $this->info("发送成功: {$result['success']}");
        $this->info("发送失败: {$result['failed']}");
        
        // 显示详细结果
        if (!empty($result['details'])) {
            $this->info("\n详细结果:");
            foreach ($result['details'] as $detail) {
                $status = $detail['status'] === 'success' ? '✅' : '❌';
                $error = isset($detail['error']) ? " ({$detail['error']})" : '';
                $this->info("{$status} {$detail['user']} (TG: {$detail['telegram_id']}){$error}");
            }
        }
        
        return $result['failed'] === 0 ? 0 : 1; // 如果有失败则返回错误码
    }
}
