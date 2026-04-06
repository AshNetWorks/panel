<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Models\UserCheckin;
use App\Models\UserCheckinStats;
use App\Plugins\Telegram\Telegram;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CheckinRank extends Telegram
{
    public $command = '/checkin_rank';
    public $description = '签到排行榜';

    public function handle($message, $match = [])
    {
        $telegramService = $this->telegramService;

        // 区分私聊和群组
        $isPrivate = $message->is_private;
        $chatId = $isPrivate ? $message->chat_id : $message->from_id;

        $user = User::where('telegram_id', $chatId)->first();
        if (!$user) {
            $text = '❌ 没有查询到您的用户信息，请先绑定账号';
            if ($isPrivate) {
                $telegramService->sendMessage($message->chat_id, $text, 'markdown');
            } else {
                $telegramService->sendMessageWithAutoDelete($message->chat_id, $text, 'markdown', 60, $message->message_id);
            }
            return;
        }

        // 解析参数: /checkin_rank [today|week|month|all]
        $period = 'today'; // 默认今日
        if (isset($message->text)) {
            $parts = explode(' ', $message->text);
            if (count($parts) > 1) {
                $param = strtolower(trim($parts[1]));
                if (in_array($param, ['today', 'week', 'month', 'all'])) {
                    $period = $param;
                }
            }
        }

        // 根据period确定日期范围
        switch ($period) {
            case 'today':
                $startDate = Carbon::now()->format('Y-m-d');
                $title = '今日';
                break;
            case 'week':
                $startDate = Carbon::now()->subDays(7)->format('Y-m-d');
                $title = '本周';
                break;
            case 'month':
                $startDate = Carbon::now()->subDays(30)->format('Y-m-d');
                $title = '本月';
                break;
            case 'all':
            default:
                $startDate = null;
                $title = '总榜';
                break;
        }

        // 今日/周/月榜单：基于 UserCheckin 表的实时数据
        if ($period !== 'all') {
            // 获得流量最多的用户（奖励榜）
            $rewardRankings = UserCheckin::where('checkin_date', '>=', $startDate)
                ->where('traffic_type', 1)
                ->select('user_id', DB::raw('SUM(traffic_amount) as total_reward'))
                ->groupBy('user_id')
                ->orderBy('total_reward', 'DESC')
                ->limit(10)
                ->get();

            // 被扣流量最多的用户（惩罚榜）
            $penaltyRankings = UserCheckin::where('checkin_date', '>=', $startDate)
                ->where('traffic_type', 0)
                ->select('user_id', DB::raw('SUM(traffic_amount) as total_penalty'))
                ->groupBy('user_id')
                ->orderBy('total_penalty', 'DESC')
                ->limit(10)
                ->get();

            // 签到次数最多的用户
            $checkinCountRankings = UserCheckin::where('checkin_date', '>=', $startDate)
                ->select('user_id', DB::raw('COUNT(*) as checkin_count'))
                ->groupBy('user_id')
                ->orderBy('checkin_count', 'DESC')
                ->limit(10)
                ->get();

            // 净流量榜（奖励 - 惩罚）
            $netTrafficRankings = UserCheckin::where('checkin_date', '>=', $startDate)
                ->select('user_id',
                    DB::raw('SUM(CASE WHEN traffic_type = 1 THEN traffic_amount ELSE -traffic_amount END) as net_traffic'))
                ->groupBy('user_id')
                ->orderBy('net_traffic', 'DESC')
                ->limit(10)
                ->get();
        } else {
            // 总榜：基于 UserCheckinStats 表的累计数据
            // 连续签到天数排行
            $streakRankings = UserCheckinStats::where('checkin_streak', '>', 0)
                ->orderBy('checkin_streak', 'DESC')
                ->orderBy('last_checkin_at', 'DESC')
                ->limit(10)
                ->get();

            // 累计签到天数排行
            $totalDaysRankings = UserCheckinStats::where('total_checkin_days', '>', 0)
                ->orderBy('total_checkin_days', 'DESC')
                ->orderBy('last_checkin_at', 'DESC')
                ->limit(10)
                ->get();

            // 净流量榜（可能为负数）
            $netTrafficRankings = UserCheckinStats::orderBy('total_checkin_traffic', 'DESC')
                ->limit(10)
                ->get();

            // 倒霉蛋榜（净流量最低，亏损最多）
            $unluckyRankings = UserCheckinStats::where('total_checkin_traffic', '<', 0)
                ->orderBy('total_checkin_traffic', 'ASC')
                ->limit(10)
                ->get();
        }

        // 批量预加载所有榜单涉及的用户（避免 N+1 查询）
        $allUserIds = [];
        if ($period !== 'all') {
            foreach ([$rewardRankings, $penaltyRankings, $checkinCountRankings, $netTrafficRankings] as $ranking) {
                $allUserIds = array_merge($allUserIds, $ranking->pluck('user_id')->toArray());
            }
        } else {
            foreach ([$streakRankings, $totalDaysRankings, $netTrafficRankings, $unluckyRankings] as $ranking) {
                $allUserIds = array_merge($allUserIds, $ranking->pluck('user_id')->toArray());
            }
        }
        $usersMap = User::whereIn('id', array_unique($allUserIds))
            ->select('id', 'email', 'telegram_id')
            ->get()
            ->keyBy('id');

        // 构建消息
        $msg = "🏆 *签到排行榜 - {$title}*\n";
        $msg .= "━━━━━━━━━━━━━━━━\n\n";

        if ($period !== 'all') {
            // 今日/周/月榜单
            // 净流量榜（最赚的）
            $msg .= "💰 *净流量榜*（奖励-惩罚）\n";
            if ($netTrafficRankings->isEmpty()) {
                $msg .= "暂无数据\n\n";
            } else {
                foreach ($netTrafficRankings as $index => $item) {
                    $rank = $index + 1;
                    $medal = $this->getMedal($rank);
                    $rankUser = $usersMap[$item->user_id] ?? null;
                    if ($rankUser) {
                        $userName = $this->getTelegramDisplayName($rankUser->telegram_id, $telegramService);
                        $traffic = $this->formatTraffic($item->net_traffic);
                        $prefix = $item->net_traffic >= 0 ? '+' : '';
                        $msg .= "{$medal} {$userName} {$prefix}{$traffic}\n";
                    }
                }
                $msg .= "\n";
            }

            // 奖励榜
            $msg .= "🎁 *奖励榜*\n";
            if ($rewardRankings->isEmpty()) {
                $msg .= "暂无数据\n\n";
            } else {
                foreach ($rewardRankings as $index => $item) {
                    $rank = $index + 1;
                    $medal = $this->getMedal($rank);
                    $rankUser = $usersMap[$item->user_id] ?? null;
                    if ($rankUser) {
                        $userName = $this->getTelegramDisplayName($rankUser->telegram_id, $telegramService);
                        $traffic = $this->formatTraffic($item->total_reward);
                        $msg .= "{$medal} {$userName} +{$traffic}\n";
                    }
                }
                $msg .= "\n";
            }

            // 惩罚榜
            $msg .= "🤡 *惩罚榜*\n";
            if ($penaltyRankings->isEmpty()) {
                $msg .= "暂无数据\n\n";
            } else {
                foreach ($penaltyRankings as $index => $item) {
                    $rank = $index + 1;
                    $medal = $this->getMedal($rank);
                    $rankUser = $usersMap[$item->user_id] ?? null;
                    if ($rankUser) {
                        $userName = $this->getTelegramDisplayName($rankUser->telegram_id, $telegramService);
                        $traffic = $this->formatTraffic($item->total_penalty);
                        $msg .= "{$medal} {$userName} -{$traffic}\n";
                    }
                }
                $msg .= "\n";
            }

            // 签到次数榜
            if ($period !== 'today') {
                $msg .= "📅 *签到次数榜*\n";
                if ($checkinCountRankings->isEmpty()) {
                    $msg .= "暂无数据\n\n";
                } else {
                    foreach ($checkinCountRankings as $index => $item) {
                        $rank = $index + 1;
                        $medal = $this->getMedal($rank);
                        $rankUser = User::find($item->user_id);
                        if ($rankUser) {
                            $userName = $this->getTelegramDisplayName($rankUser->telegram_id, $telegramService);
                            $msg .= "{$medal} {$userName} {$item->checkin_count}次\n";
                        }
                    }
                    $msg .= "\n";
                }
            }
        } else {
            // 总榜
            // 连续签到榜
            $msg .= "🔥 *连续签到榜*\n";
            if ($streakRankings->isEmpty()) {
                $msg .= "暂无数据\n\n";
            } else {
                foreach ($streakRankings as $index => $stats) {
                    $rank = $index + 1;
                    $medal = $this->getMedal($rank);
                    $rankUser = $usersMap[$stats->user_id] ?? null;
                    if ($rankUser) {
                        $userName = $this->getTelegramDisplayName($rankUser->telegram_id, $telegramService);
                        $msg .= "{$medal} {$userName} {$stats->checkin_streak}天\n";
                    }
                }
                $msg .= "\n";
            }

            // 累计签到天数榜
            $msg .= "📅 *累计签到榜*\n";
            if ($totalDaysRankings->isEmpty()) {
                $msg .= "暂无数据\n\n";
            } else {
                foreach ($totalDaysRankings as $index => $stats) {
                    $rank = $index + 1;
                    $medal = $this->getMedal($rank);
                    $rankUser = $usersMap[$stats->user_id] ?? null;
                    if ($rankUser) {
                        $userName = $this->getTelegramDisplayName($rankUser->telegram_id, $telegramService);
                        $msg .= "{$medal} {$userName} {$stats->total_checkin_days}天\n";
                    }
                }
                $msg .= "\n";
            }

            // 净流量榜
            $msg .= "💰 *净流量榜*（历史累计）\n";
            if ($netTrafficRankings->isEmpty()) {
                $msg .= "暂无数据\n\n";
            } else {
                foreach ($netTrafficRankings as $index => $stats) {
                    $rank = $index + 1;
                    $medal = $this->getMedal($rank);
                    $rankUser = $usersMap[$stats->user_id] ?? null;
                    if ($rankUser) {
                        $userName = $this->getTelegramDisplayName($rankUser->telegram_id, $telegramService);
                        $traffic = $this->formatTraffic($stats->total_checkin_traffic);
                        $prefix = $stats->total_checkin_traffic >= 0 ? '+' : '';
                        $msg .= "{$medal} {$userName} {$prefix}{$traffic}\n";
                    }
                }
                $msg .= "\n";
            }

            // 倒霉蛋榜
            if (!$unluckyRankings->isEmpty()) {
                $msg .= "🤡 *倒霉蛋榜*（亏损最多）\n";
                foreach ($unluckyRankings as $index => $stats) {
                    $rank = $index + 1;
                    $medal = $this->getMedal($rank);
                    $rankUser = $usersMap[$stats->user_id] ?? null;
                    if ($rankUser) {
                        $userName = $this->getTelegramDisplayName($rankUser->telegram_id, $telegramService);
                        $traffic = $this->formatTraffic(abs($stats->total_checkin_traffic));
                        $msg .= "{$medal} {$userName} -{$traffic}\n";
                    }
                }
                $msg .= "\n";
            }
        }

        // 使用说明
        $msg .= "━━━━━━━━━━━━━━━━\n";
        $msg .= "💡 *使用说明*\n";
        $msg .= "• /checkin_rank - 今日排行\n";
        $msg .= "• /checkin_rank week - 本周排行\n";
        $msg .= "• /checkin_rank month - 本月排行\n";
        $msg .= "• /checkin_rank all - 历史总榜";

        if ($isPrivate) {
            $telegramService->sendMessage($message->chat_id, $msg, 'markdown');
        } else {
            $telegramService->sendMessageWithAutoDelete($message->chat_id, $msg, 'markdown', 60, $message->message_id);
        }
    }

    /**
     * 获取排名奖牌
     */
    private function getMedal($rank)
    {
        switch ($rank) {
            case 1:
                return '🥇';
            case 2:
                return '🥈';
            case 3:
                return '🥉';
            default:
                return str_pad($rank, 2, '0', STR_PAD_LEFT) . '.';
        }
    }

    /**
     * 通过 Telegram ID 获取用户显示名称（username 优先，否则 first_name）
     */
    private function getTelegramDisplayName($telegramId, $telegramService): string
    {
        if (!$telegramId) {
            return 'User';
        }
        try {
            $chat = $telegramService->getChat($telegramId);
            if ($chat) {
                if (!empty($chat->username)) {
                    return '@' . $chat->username;
                }
                $name = trim(($chat->first_name ?? '') . ' ' . ($chat->last_name ?? ''));
                if ($name !== '') {
                    return $this->escapeName($name);
                }
            }
        } catch (\Exception $e) {
            \Log::warning('CheckinRank: 获取 Telegram 用户信息失败', [
                'telegram_id' => $telegramId,
                'error' => $e->getMessage(),
            ]);
        }
        return 'User ' . $telegramId;
    }

    /**
     * 转义名称中不由 sendMessage 统一处理的 Markdown 特殊字符
     * 注意：_ 由 sendMessage('markdown') 统一转义，此处不处理，避免双重转义
     */
    private function escapeName($text): string
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

    /**
     * 转义 Markdown 特殊字符
     */
    private function escapeMarkdown($text)
    {
        $escapeChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        foreach ($escapeChars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }
        return $text;
    }
}
