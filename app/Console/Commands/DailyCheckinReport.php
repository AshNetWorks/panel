<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserCheckin;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DailyCheckinReport extends Command
{
    protected $signature = 'checkin:daily-report';
    protected $description = '每日0点播报昨日的欧皇和非酋';

    public function handle()
    {
        $this->info('开始生成每日签到榜单...');

        try {
            $telegramService = new TelegramService();

            // 获取群组 Chat ID（与 Join 命令保持一致）
            $groupChatId = config('v2board.telegram_group_id');

            // 获取昨日数据（0点播报的是昨天一整天的数据）
            $yesterday = Carbon::yesterday()->format('Y-m-d');

            $this->info("统计日期: {$yesterday}");

            // 查询昨日欧皇：获得奖励流量最多的用户（traffic_type = 1）
            $luckyCheckin = UserCheckin::where('checkin_date', $yesterday)
                ->where('traffic_type', 1)
                ->orderBy('traffic_amount', 'desc')
                ->first();

            // 查询昨日非酋：被扣除流量最多的用户（traffic_type = 0）
            $unluckyCheckin = UserCheckin::where('checkin_date', $yesterday)
                ->where('traffic_type', 0)
                ->orderBy('traffic_amount', 'desc')
                ->first();

            // 统计昨日签到总人数
            $totalCheckins = UserCheckin::where('checkin_date', $yesterday)
                ->distinct('user_id')
                ->count('user_id');

            // 统计昨日奖励总流量
            $totalReward = UserCheckin::where('checkin_date', $yesterday)
                ->where('traffic_type', 1)
                ->sum('traffic_amount');

            // 统计昨日惩罚总流量
            $totalPenalty = UserCheckin::where('checkin_date', $yesterday)
                ->where('traffic_type', 0)
                ->sum('traffic_amount');

            // 构建播报消息（显示昨天的日期）
            $msg = "📊 *每日签到榜单* - " . Carbon::yesterday()->format('Y年m月d日') . "\n";
            $msg .= "━━━━━━━━━━━━━━━━\n\n";

            // 欧皇榜
            $luckyUser = $luckyCheckin ? User::find($luckyCheckin->user_id) : null;
            if ($luckyCheckin) {
                $luckyTraffic = $this->formatTraffic($luckyCheckin->traffic_amount);
                $msg .= "👑 *昨日欧皇*\n";
                if ($luckyUser && $luckyUser->telegram_id) {
                    $luckyUserName = $this->getTelegramDisplayName($luckyUser->telegram_id, $telegramService);
                    $msg .= "• {$luckyUserName}\n";
                }
                $msg .= "• 获得流量：+{$luckyTraffic}\n\n";
            } else {
                $msg .= "👑 *昨日欧皇*\n";
                $msg .= "• 暂无数据\n\n";
            }

            // 非酋榜
            $unluckyUser = $unluckyCheckin ? User::find($unluckyCheckin->user_id) : null;
            if ($unluckyCheckin) {
                $unluckyTraffic = $this->formatTraffic($unluckyCheckin->traffic_amount);
                $msg .= "🤡 *昨日非酋*\n";
                if ($unluckyUser && $unluckyUser->telegram_id) {
                    $unluckyUserName = $this->getTelegramDisplayName($unluckyUser->telegram_id, $telegramService);
                    $msg .= "• {$unluckyUserName}\n";
                }
                $msg .= "• 损失流量：-{$unluckyTraffic}\n\n";
            } else {
                $msg .= "🤡 *昨日非酋*\n";
                $msg .= "• 暂无数据\n\n";
            }

            // 统计数据
            $msg .= "━━━━━━━━━━━━━━━━\n";
            $msg .= "📈 *昨日签到统计*\n";
            $msg .= "• 签到人数：{$totalCheckins} 人\n";
            $msg .= "• 总奖励流量：" . $this->formatTraffic($totalReward) . "\n";
            $msg .= "• 总惩罚流量：" . $this->formatTraffic($totalPenalty) . "\n";

            $netTraffic = $totalReward - $totalPenalty;
            $netPrefix = $netTraffic >= 0 ? '+' : '';
            $msg .= "• 净流量：{$netPrefix}" . $this->formatTraffic(abs($netTraffic)) . "\n\n";

            $msg .= "💡 使用 /checkin 命令进行签到";

            // 发送到群组
            $response = $telegramService->sendMessage($groupChatId, $msg, 'markdown');

            if ($response && isset($response->ok) && $response->ok) {
                $this->info('✅ 每日签到榜单已发送到群组');
                $this->info("群组 ID: {$groupChatId}");
                $this->info("签到人数: {$totalCheckins}");

                if ($luckyCheckin) {
                    $this->info("欧皇: " . ($luckyUser?->email ?? 'Unknown') . " (+" . $this->formatTraffic($luckyCheckin->traffic_amount) . ")");
                }

                if ($unluckyCheckin) {
                    $this->info("非酋: " . ($unluckyUser?->email ?? 'Unknown') . " (-" . $this->formatTraffic($unluckyCheckin->traffic_amount) . ")");
                }

                return 0;
            } else {
                $this->error('❌ 发送消息失败');
                $this->error('响应: ' . json_encode($response));
                return 1;
            }

        } catch (\Exception $e) {
            $this->error('发生错误: ' . $e->getMessage());
            Log::error('每日签到播报失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * 通过 Telegram ID 获取显示名称（username 优先，否则 first_name）
     */
    private function getTelegramDisplayName($telegramId, $telegramService): string
    {
        if (!$telegramId) {
            return 'User';
        }
        try {
            $chat = $telegramService->getChat($telegramId);
            if ($chat) {
                $name = trim(($chat->first_name ?? '') . ' ' . ($chat->last_name ?? ''));
                if ($name !== '') {
                    return $this->escapeName($name);
                }
                if (!empty($chat->username)) {
                    return $this->escapeName($chat->username);
                }
            }
        } catch (\Exception $e) {
            Log::warning('DailyCheckinReport: 获取 Telegram 用户信息失败', [
                'telegram_id' => $telegramId,
                'error' => $e->getMessage(),
            ]);
        }
        return 'User ' . $telegramId;
    }

    /**
     * 转义名称中的 Markdown 特殊字符
     * 注意：_ 由 sendMessage('markdown') 统一转义，此处不处理，避免双重转义
     */
    private function escapeName(string $text): string
    {
        return str_replace(
            ['*', '[', ']', '`'],
            ['\\*', '\\[', '\\]', '\\`'],
            $text
        );
    }

    /**
     * 格式化流量显示
     */
    private function formatTraffic($bytes)
    {
        $mb = $bytes / 1048576; // 转换为 MB

        if (abs($mb) >= 1024) {
            // 大于等于 1024MB，转换为 GB
            $gb = $mb / 1024;
            return number_format($gb, 2) . ' GB';
        } else {
            // 小于 1024MB，显示为 MB
            return number_format($mb, 2) . ' MB';
        }
    }
}
