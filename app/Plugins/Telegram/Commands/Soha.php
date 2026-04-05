<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Models\UserCheckin;
use App\Plugins\Telegram\Telegram;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class Soha extends Telegram
{
    public $command = '/soha';
    public $description = '流量梭哈，押注5GB进行抽奖（每日一次）';

    /**
     * 功能开关：true=启用梭哈，false=关闭梭哈（引导用户去 /checkin）
     */
    private const ENABLED = true;

    private const BET_AMOUNT = 5 * 1024 * 1024 * 1024; // 5GB

    /**
     * 概率分布表：[累计阈值(万分比), 档位名, 基础倍率, 浮动范围]
     * 总体期望：60%亏损 / 17%保本 / 23%盈利，期望回报率≈78.7%
     */
    private const TIERS = [
        [2800,  'small_loss',  0.6,  0.3],  // 28% 小亏 0.6-0.9x
        [4800,  'medium_loss', 0.3,  0.3],  // 20% 中亏 0.3-0.6x
        [6000,  'total_loss',  0,    0],     // 12% 全输 0x
        [7700,  'break_even',  0.95, 0.1],  // 17% 保本 0.95-1.05x
        [9700,  'small_win',   1.1,  0.3],  // 20% 小赚 1.1-1.4x
        [9950,  'double',      1.8,  0.4],  // 2.5% 翻倍 1.8-2.2x
        [9990,  'big_win',     2.5,  1.0],  // 0.4% 大赚 2.5-3.5x
        [9998,  'burst',       4.0,  1.0],  // 0.08% 爆发 4-5x
        [10000, 'legend',      8.0,  2.0],  // 0.02% 传说 8-10x
    ];

    private const TIER_EMOJI = [
        'small_loss' => '💸', 'medium_loss' => '😔', 'total_loss' => '💀',
        'break_even' => '😐', 'small_win' => '😊', 'double' => '🎉',
        'big_win' => '🎊', 'burst' => '💎', 'legend' => '🌟',
    ];

    // ── 主流程 ──────────────────────────────────────────

    public function handle($message, $match = [])
    {
        try {
            $telegramService = $this->telegramService;
            $isPrivate = $message->is_private ?? true;
            $chatId = $isPrivate ? $message->chat_id : $message->from_id;

            // 1. 前置校验
            // 功能开关检查
            if (!self::ENABLED) {
                return $this->reply($telegramService, $message, $isPrivate,
                    "📢 梭哈功能已关闭，请使用 /checkin 进行每日签到！");
            }

            $user = User::where('telegram_id', $chatId)->first();
            if (!$user) {
                return $this->reply($telegramService, $message, $isPrivate,
                    '没有查询到您的用户信息，请先绑定账号');
            }

            if ($error = $this->validateSoha($user)) {
                return $this->reply($telegramService, $message, $isPrivate, $error);
            }

            $today = Carbon::now()->format('Y-m-d');

            // 检查今天是否已梭哈
            $alreadySoha = UserCheckin::where('user_id', $user->id)
                ->where('checkin_date', $today)
                ->exists();

            if ($alreadySoha) {
                return $this->reply($telegramService, $message, $isPrivate,
                    "✋ 您今天已经梭哈过了！\n\n⏰ 明天再来碰运气吧～");
            }

            // 2. 今日梭哈排名
            $rank = UserCheckin::where('checkin_date', $today)
                ->distinct('user_id')->count('user_id') + 1;

            // 3. 抽取倍率（CSPRNG真随机）
            $roll = $this->rollMultiplier();
            $multiplier = $roll['multiplier'];
            $tier = $roll['tier'];

            // 4. 计算流量变动，操作已使用流量（不修改总流量 transfer_enable）
            $finalReward = (int)(self::BET_AMOUNT * $multiplier);
            $netProfit = $finalReward - self::BET_AMOUNT;
            // 通过调整已使用流量 d 来影响剩余流量
            // 赢了(netProfit>0)：减少d，剩余增加；输了(netProfit<0)：增加d，剩余减少
            $user->d = max(0, $user->d - $netProfit);
            $user->save();

            // 5. 记录梭哈
            UserCheckin::create([
                'user_id'        => $user->id,
                'checkin_date'   => $today,
                'traffic_amount' => abs($netProfit),
                'traffic_type'   => $netProfit >= 0 ? 1 : 0,
                'is_bonus'       => false,
                'created_at'     => time(),
            ]);

            // 6. 构建并发送消息
            $msg = $this->buildMessage($user, $netProfit, $multiplier, $tier, $rank, $chatId, $telegramService);

            if ($isPrivate) {
                $telegramService->sendMessage($message->chat_id, $msg, 'markdown');
            } else {
                $telegramService->sendMessageWithAutoDelete(
                    $message->chat_id, $msg, 'markdown', 90, $message->message_id
                );
                try {
                    $telegramService->sendMessage($chatId, $msg, 'markdown');
                } catch (\Exception $e) {
                    Log::warning('群组梭哈后私发失败', [
                        'user_id' => $user->id, 'error' => $e->getMessage()
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error('梭哈功能发生错误', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            try {
                if (isset($telegramService, $message, $isPrivate)) {
                    $this->reply($telegramService, $message, $isPrivate,
                        "梭哈失败！发生了一个错误，请稍后重试。");
                }
            } catch (\Exception $sendError) {
                Log::error('发送错误消息失败', ['error' => $sendError->getMessage()]);
            }
        }
    }

    // ── 前置校验 ────────────────────────────────────────

    private function validateSoha($user)
    {
        if (!$user->plan_id || !$user->expired_at || $user->banned == 1) {
            return "❌ *梭哈失败*\n\n您的套餐已过期或未激活。\n\n💡 请续费套餐后再来梭哈。";
        }

        $expiredAt = is_numeric($user->expired_at)
            ? Carbon::createFromTimestamp($user->expired_at)
            : Carbon::parse($user->expired_at);

        if (!$expiredAt->isFuture()) {
            return "❌ *梭哈失败*\n\n您的套餐已过期。\n\n"
                . "📅 过期时间：" . $expiredAt->format('Y-m-d H:i:s') . "\n\n"
                . "💡 请续费套餐后再来梭哈。";
        }

        $available = $user->transfer_enable - ($user->u + $user->d);
        if ($available < self::BET_AMOUNT) {
            return "❌ *流量不足，梭不动*\n\n"
                . "梭哈需要：" . $this->formatTraffic(self::BET_AMOUNT) . "\n"
                . "当前剩余：" . $this->formatTraffic($available) . "\n\n"
                . "💡 请先充值流量套餐";
        }

        return null;
    }

    // ── 概率抽取（CSPRNG真随机）───────────────────────────

    private function rollMultiplier()
    {
        $rand = random_int(0, 9999);

        foreach (self::TIERS as [$threshold, $tier, $base, $range]) {
            if ($rand < $threshold) {
                $multiplier = ($base == 0 && $range == 0)
                    ? 0
                    : $base + random_int(0, 1000) / 1000.0 * $range;
                return ['multiplier' => $multiplier, 'tier' => $tier];
            }
        }

        return ['multiplier' => 0, 'tier' => 'total_loss'];
    }

    // ── 消息构建 ────────────────────────────────────────

    private function buildMessage($user, $netProfit, $rollMultiplier, $tier, $rank, $chatId, $telegramService)
    {
        $userName = $this->getUserName($chatId, $telegramService);
        $emoji = self::TIER_EMOJI[$tier] ?? '🎲';
        $trafficDisplay = $this->formatTraffic(abs($netProfit));

        // 幽默故事
        if ($netProfit > 0) {
            $story = $this->getWinStory($trafficDisplay);
        } elseif ($netProfit === 0) {
            $story = $this->getDrawStory();
        } else {
            $story = $this->getLoseStory($trafficDisplay);
        }

        $msg = "{$emoji} *梭哈结算！*\n";
        $msg .= "👤 {$userName}\n";
        $msg .= "🎰 今日第 *{$rank}* 位梭哈\n\n";
        $msg .= "🎲 {$story}\n";

        // 投注详情
        $betDisplay = $this->formatTraffic(self::BET_AMOUNT);
        $returnAmount = (int)(self::BET_AMOUNT * $rollMultiplier);
        $returnDisplay = $this->formatTraffic($returnAmount);
        $rateDisplay = number_format($rollMultiplier, 2) . 'x';
        $profitSign = $netProfit >= 0 ? '+' : '-';
        $profitDisplay = $profitSign . $this->formatTraffic(abs($netProfit));

        $msg .= "\n💰 *投注详情*\n";
        $msg .= "• 投入：{$betDisplay}\n";
        $msg .= "• 倍率：*{$rateDisplay}*\n";
        $msg .= "• 返还：{$returnDisplay}\n";
        $msg .= "• 盈亏：*{$profitDisplay}*\n";

        // 当前流量
        $used = $user->u + $user->d;
        $total = $user->transfer_enable;
        $msg .= "\n📊 *当前流量*\n";
        $msg .= "• 已用：" . $this->formatTraffic($used) . "\n";
        $msg .= "• 剩余：" . $this->formatTraffic($total - $used) . "\n";
        $msg .= "• 总计：" . $this->formatTraffic($total) . "\n\n";

        // 今日梭哈欧皇/非酋
        $msg .= $this->getTodayRanking($telegramService);

        return $msg;
    }

    // ── 幽默故事 ────────────────────────────────────────

    private function getWinStory($traffic)
    {
        $stories = [
            "🎁 梭哈之神降临！天降 *{$traffic}*",
            "🎉 All in 赢麻了！收获 *{$traffic}*",
            "✨ 命运眷顾赌神！净赚 *{$traffic}*",
            "💎 一把梭中了宝藏，到手 *{$traffic}*",
            "🎊 流量赌场大赢家！喜提 *{$traffic}*",
            "🚀 梭哈火箭直冲云霄，捞回 *{$traffic}*",
            "🏆 赌王就是你！荣获 *{$traffic}*",
            "🎯 精准梭哈！命中 *{$traffic}*",
            "⚡ 梭哈暴击！爆出 *{$traffic}*",
            "🍀 运气全开！白赚 *{$traffic}*",
            "💰 一夜暴富！进账 *{$traffic}*",
            "🎸 梭出了传说！到账 *{$traffic}*",
            "🌟 欧皇附体！获得 *{$traffic}*",
            "🎪 赌场今天是你家开的，赢了 *{$traffic}*",
            "🔥 手气爆表！净收 *{$traffic}*",
        ];
        return $stories[random_int(0, count($stories) - 1)];
    }

    private function getLoseStory($traffic)
    {
        $stories = [
            "💀 梭哈翻车！血亏 *{$traffic}*",
            "😵 All in 全没了...赔了 *{$traffic}*",
            "🫠 流量蒸发了 *{$traffic}*，下次再梭",
            "😭 输麻了！损失 *{$traffic}*",
            "💸 赌神变赌狗，亏了 *{$traffic}*",
            "🌪️ 一把梭飞了 *{$traffic}*",
            "🤡 今天非酋上身，丢了 *{$traffic}*",
            "😬 梭出个寂寞，白送 *{$traffic}*",
            "🎭 流量去了没有回来，走了 *{$traffic}*",
            "⏰ 明天再来报仇！今天亏了 *{$traffic}*",
            "🫣 赌桌上血流成河，流走 *{$traffic}*",
            "😵‍💫 梭哈后一阵眩晕，醒来少了 *{$traffic}*",
            "🪦 这把梭的...安息吧 *{$traffic}*",
            "🎲 骰子不争气，赔了 *{$traffic}*",
            "🥲 流量说再见，带走了 *{$traffic}*",
        ];
        return $stories[random_int(0, count($stories) - 1)];
    }

    private function getDrawStory()
    {
        $stories = [
            "😐 梭了个寂寞...不亏不赚",
            "🤔 赌神思考了一下，决定平局",
            "⚖️ 完美平衡，不多不少",
            "🫤 梭了等于没梭，白忙一场",
            "😶 命运说：今天不想理你",
            "🪨 铁打的流量，纹丝不动",
        ];
        return $stories[random_int(0, count($stories) - 1)];
    }

    // ── 今日排行（只统计梭哈记录）─────────────────────────

    private function getTodayRanking($telegramService)
    {
        try {
            $today = Carbon::now()->format('Y-m-d');

            $lucky = UserCheckin::where('checkin_date', $today)
                ->where('traffic_type', 1)
                ->orderByDesc('traffic_amount')
                ->first();

            $unlucky = UserCheckin::where('checkin_date', $today)
                ->where('traffic_type', 0)
                ->orderByDesc('traffic_amount')
                ->first();

            $result = $this->formatRankLine('👑 今日欧皇', $lucky, '+', $telegramService);
            $result .= "\n" . $this->formatRankLine('🤡 今日非酋', $unlucky, '-', $telegramService);

            return $result;
        } catch (\Exception $e) {
            Log::error('获取梭哈排行失败', ['error' => $e->getMessage()]);
            return "👑 今日欧皇 暂无\n🤡 今日非酋 暂无";
        }
    }

    private function formatRankLine($label, $checkin, $prefix, $telegramService)
    {
        if (!$checkin) {
            return "{$label} 暂无";
        }

        $traffic = $this->formatTraffic($checkin->traffic_amount);
        $user = User::find($checkin->user_id);

        if ($user) {
            $name = $this->escape(strstr($user->email, '@', true) ?: $user->email);
            return "{$label} {$name} {$prefix}{$traffic}";
        }

        return "{$label} {$prefix}{$traffic}";
    }

    // ── 工具方法 ────────────────────────────────────────

    private function formatTraffic($bytes)
    {
        $mb = abs($bytes) / 1048576;
        return $mb >= 1024
            ? number_format($mb / 1024, 2) . ' GB'
            : number_format($mb, 2) . ' MB';
    }

    private function getUserName($telegramId, $telegramService)
    {
        try {
            $chat = $telegramService->getChat($telegramId);
            if ($chat) {
                $name = trim(($chat->first_name ?? '') . ' ' . ($chat->last_name ?? ''));
                $display = $name ?: ($chat->username ?? '');
                if ($display) {
                    return "[{$this->escape($display)}](tg://user?id={$telegramId})";
                }
            }
        } catch (\Exception $e) {
            Log::error('获取用户信息失败', ['telegram_id' => $telegramId]);
        }
        return 'User ' . $telegramId;
    }

    private function getDisplayName($telegramId, $telegramService)
    {
        try {
            $chat = $telegramService->getChat($telegramId);
            if ($chat && !empty($chat->first_name)) {
                return $this->escape($chat->first_name);
            }
        } catch (\Exception $e) {}
        return 'User ' . $telegramId;
    }

    private function escape($text)
    {
        // 不转义 _，sendMessage('markdown') 已统一处理下划线转义，避免双重转义
        return str_replace(
            ['*', '[', ']', '`'],
            ['\\*', '\\[', '\\]', '\\`'],
            $text
        );
    }

    private function reply($telegramService, $message, $isPrivate, $text)
    {
        if ($isPrivate) {
            $telegramService->sendMessage($message->chat_id, $text, 'markdown');
        } else {
            $telegramService->sendMessageWithAutoDelete(
                $message->chat_id, $text, 'markdown', 60, $message->message_id
            );
        }
    }
}
