#!/bin/bash
echo "=== 队列状态监控 $(date) ==="

# 检查队列进程
QUEUE_PROC=$(ps aux | grep "queue:work" | grep -v grep | wc -l)
echo "队列进程数: $QUEUE_PROC"

# 检查待处理订单
PENDING=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo \Illuminate\Support\Facades\DB::table('v2_order')->where('status', 0)->count();")
echo "待处理订单: $PENDING"

if [ "$QUEUE_PROC" -eq 0 ]; then
    echo "⚠️  队列进程已停止，正在重启..."
    cd /www/wwwroot/panel
    nohup php artisan queue:work --daemon > storage/logs/queue.log 2>&1 &
    echo "✅ 队列进程已重启"
fi

if [ "$PENDING" -gt 0 ]; then
    echo "⚠️  发现 $PENDING 个待处理订单"
fi
