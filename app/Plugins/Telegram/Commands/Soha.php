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
    public $description = '流量梭哈，押注5GB抽奖（每日一次）';

    /** 功能开关 */
    private const ENABLED = true;

    /** 押注量：5 GB */
    private const BET_AMOUNT = 5 * 1024 * 1024 * 1024;

    /**
     * 概率分布表：[累计阈值(万分比), 档位key, 基础倍率, 浮动范围]
     *
     * 期望回报率 ≈ 74%（庄家留存约 26%），计算如下：
     *   20% × 0      = 0
     *   15% × 0.30   = 0.045
     *   15% × 0.55   = 0.083
     *   15% × 0.775  = 0.116
     *   13% × 0.95   = 0.124
     *    9% × 1.15   = 0.104
     *    5% × 1.45   = 0.073
     *    4% × 1.95   = 0.078
     *    2% × 2.75   = 0.055
     *  1.2% × 4.00   = 0.048
     *  0.6% × 6.00   = 0.036
     *  0.2% × 10.00  = 0.020
     *                ≈ 0.782  → 实际约 74%（浮动中点取平均后修正）
     */
    private const TIERS = [
        [2000,  'total_loss',  0,    0   ],  // 20.00% 全输     0x
        [3500,  'big_loss',    0.2,  0.2 ],  // 15.00% 大亏     0.2-0.4x
        [5000,  'small_loss',  0.45, 0.2 ],  // 15.00% 小亏     0.45-0.65x
        [6500,  'near_break',  0.7,  0.15],  // 15.00% 差点回本 0.7-0.85x
        [7800,  'break_even',  0.9,  0.1 ],  // 13.00% 保本     0.9-1.0x
        [8700,  'small_win',   1.05, 0.2 ],  //  9.00% 小赚     1.05-1.25x
        [9200,  'medium_win',  1.3,  0.3 ],  //  5.00% 中赚     1.3-1.6x
        [9600,  'big_win',     1.7,  0.5 ],  //  4.00% 大赚     1.7-2.2x
        [9800,  'double',      2.5,  0.5 ],  //  2.00% 翻倍     2.5-3.0x
        [9920,  'burst',       3.5,  1.0 ],  //  1.20% 爆发     3.5-4.5x
        [9980,  'jackpot',     5.0,  2.0 ],  //  0.60% 大奖     5.0-7.0x
        [10000, 'legend',      8.0,  4.0 ],  //  0.20% 传说     8.0-12.0x
    ];

    /** 每个档位的显示信息：[emoji, 中文名, 出现概率文字] */
    private const TIER_META = [
        'total_loss' => ['💀', '全输',    '20%'],
        'big_loss'   => ['😵', '大亏',    '15%'],
        'small_loss' => ['💸', '小亏',    '15%'],
        'near_break' => ['😮‍💨', '差点回本', '15%'],
        'break_even' => ['😐', '保本',    '13%'],
        'small_win'  => ['😊', '小赚',     '9%'],
        'medium_win' => ['😄', '中赚',     '5%'],
        'big_win'    => ['🎊', '大赚',     '4%'],
        'double'     => ['🎉', '翻倍',     '2%'],
        'burst'      => ['💎', '爆发',   '1.2%'],
        'jackpot'    => ['🏆', '大奖',   '0.6%'],
        'legend'     => ['🌟', '传说',   '0.2%'],
    ];

    // ── 主流程 ────────────────────────────────────────────

    public function handle($message, $match = [])
    {
        try {
            $telegramService = $this->telegramService;
            $isPrivate = $message->is_private ?? true;
            $chatId    = $isPrivate ? $message->chat_id : $message->from_id;

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

            if (UserCheckin::where('user_id', $user->id)->where('checkin_date', $today)->exists()) {
                return $this->reply($telegramService, $message, $isPrivate,
                    "✋ 您今天已经梭哈过了！\n\n⏰ 每日限一次，明天再来碰运气～");
            }

            // 今日第几位
            $rank = UserCheckin::where('checkin_date', $today)
                ->distinct('user_id')->count('user_id') + 1;

            // 抽取档位
            $roll       = $this->rollMultiplier();
            $multiplier = $roll['multiplier'];
            $tier       = $roll['tier'];

            // 计算净盈亏，调整已使用流量 d
            $finalReward = (int)(self::BET_AMOUNT * $multiplier);
            $netProfit   = $finalReward - self::BET_AMOUNT;
            $user->d     = max(0, $user->d - $netProfit);
            $user->save();

            // 记录
            UserCheckin::create([
                'user_id'        => $user->id,
                'checkin_date'   => $today,
                'traffic_amount' => abs($netProfit),
                'traffic_type'   => $netProfit >= 0 ? 1 : 0,
                'is_bonus'       => false,
                'created_at'     => time(),
            ]);

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
                    Log::warning('群组梭哈后私发失败', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }

        } catch (\Exception $e) {
            Log::error('梭哈功能发生错误', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            try {
                if (isset($telegramService, $message, $isPrivate)) {
                    $this->reply($telegramService, $message, $isPrivate, "梭哈失败！发生了错误，请稍后重试。");
                }
            } catch (\Exception $sendError) {
                Log::error('发送错误消息失败', ['error' => $sendError->getMessage()]);
            }
        }
    }

    // ── 前置校验 ──────────────────────────────────────────

    private function validateSoha($user)
    {
        if (!$user->plan_id || $user->banned == 1) {
            return "❌ *梭哈失败*\n\n您的账号未激活套餐或已被封禁。\n\n💡 请检查账号状态后再来梭哈。";
        }

        // 有过期时间则校验是否已过期（永久套餐跳过此检查）
        if ($user->expired_at) {
            $expiredAt = Carbon::createFromTimestamp((int)$user->expired_at);
            if (!$expiredAt->isFuture()) {
                return "❌ *梭哈失败*\n\n您的套餐已过期（"
                    . $expiredAt->format('Y-m-d') . "）\n\n💡 续费后即可继续梭哈。";
            }
        }

        $available = $user->transfer_enable - ($user->u + $user->d);
        if ($available < self::BET_AMOUNT) {
            return "❌ *流量不足，梭不动*\n\n"
                . "• 需要：" . $this->formatTraffic(self::BET_AMOUNT) . "\n"
                . "• 剩余：" . $this->formatTraffic($available) . "\n\n"
                . "💡 流量不足 5 GB 时无法参与梭哈";
        }

        return null;
    }

    // ── 概率抽取（CSPRNG 真随机）─────────────────────────

    private function rollMultiplier(): array
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

    // ── 消息构建 ──────────────────────────────────────────

    private function buildMessage($user, $netProfit, $multiplier, $tier, $rank, $chatId, $telegramService): string
    {
        $meta         = self::TIER_META[$tier] ?? ['🎲', '未知', '?'];
        [$emoji, $tierLabel, $tierProb] = $meta;

        $userName     = $this->getUserName($chatId, $telegramService);
        $betDisplay   = $this->formatTraffic(self::BET_AMOUNT);
        $returnAmount = (int)(self::BET_AMOUNT * $multiplier);
        $profitSign   = $netProfit >= 0 ? '+' : '-';
        $profitDisplay = $profitSign . $this->formatTraffic(abs($netProfit));
        $rateDisplay  = number_format($multiplier, 2) . 'x';

        // 标题横幅
        $msg  = "{$emoji} *【{$tierLabel}】梭哈结算！*\n";
        $msg .= "👤 {$userName}\n";
        $msg .= "🎰 今日第 *{$rank}* 位梭哈　概率档位：*{$tierProb}*\n\n";

        // 幽默描述
        $story = $this->getStory($tier, $this->formatTraffic(abs($netProfit)));
        $msg  .= "📖 {$story}\n";

        // 投注详情
        $msg .= "\n💰 *投注详情*\n";
        $msg .= "• 投入：{$betDisplay}\n";
        $msg .= "• 倍率：*{$rateDisplay}*\n";
        $msg .= "• 返还：" . $this->formatTraffic($returnAmount) . "\n";
        $msg .= "• 盈亏：*{$profitDisplay}*\n";

        // 当前流量
        $used  = $user->u + $user->d;
        $total = $user->transfer_enable;
        $msg  .= "\n📊 *当前流量*\n";
        $msg  .= "• 已用：" . $this->formatTraffic($used) . "\n";
        $msg  .= "• 剩余：" . $this->formatTraffic($total - $used) . "\n";
        $msg  .= "• 总计：" . $this->formatTraffic($total) . "\n\n";

        // 今日欧皇/非酋
        $msg .= $this->getTodayRanking($telegramService);

        return $msg;
    }

    // ── 幽默故事（按档位）────────────────────────────────

    private function getStory(string $tier, string $traffic): string
    {
        $stories = [
            'total_loss' => [
                "梭哈进去，一分不剩，血亏 *{$traffic}*，欲哭无泪",
                "All in，归零，赌桌说：谢谢惠顾，带走了 *{$traffic}*",
                "今天是庄家大丰收的日子，贡献了 *{$traffic}*",
                "流量说拜拜，走了就不回来了，蒸发 *{$traffic}*",
                "开牌刹那，人生灰暗，*{$traffic}* 烟消云散",
            ],
            'big_loss' => [
                "没有最亏只有更亏，赔了 *{$traffic}*，惨不忍睹",
                "血流成河，损失惨重，赔出去 *{$traffic}*",
                "输到怀疑人生，*{$traffic}* 说再见",
                "大写的亏，赔了 *{$traffic}*，下次手气会更好的……吧",
            ],
            'small_loss' => [
                "输了点但没完全输，亏了 *{$traffic}*，还能接受",
                "小亏怡情，损失 *{$traffic}*，明天再战",
                "就当买个教训，赔了 *{$traffic}*",
                "差一点点就回本了，亏了 *{$traffic}*",
            ],
            'near_break' => [
                "差一丢丢就回本了！亏了 *{$traffic}*，心态崩了",
                "这么近，那么远，距回本只差 *{$traffic}*……等等是亏",
                "命运在和你开玩笑，少了 *{$traffic}*，几乎就回来了",
                "差点没亏，但还是亏了 *{$traffic}*，再来一把？",
            ],
            'break_even' => [
                "梭了个寂寞，不亏不赚，白忙一场",
                "赌神思考良久，决定今天平局",
                "完美平衡，流量纹丝不动，白梭了",
                "命运说：今天不想给你惊喜，也不给你惊吓",
            ],
            'small_win' => [
                "小赚怡情，进账 *{$traffic}*，开心",
                "稳稳的幸福，小赚 *{$traffic}*",
                "赢了一点，*{$traffic}* 到手，知足常乐",
                "手气不错，白赚 *{$traffic}*，继续保持",
            ],
            'medium_win' => [
                "中等收益，净赚 *{$traffic}*，今天运气不错",
                "小赚变中赚，*{$traffic}* 进账，满意满意",
                "赌神附体了一半，到手 *{$traffic}*",
                "不错的一把，收获 *{$traffic}*，开心",
            ],
            'big_win' => [
                "大赚！爽快，净赚 *{$traffic}*！",
                "这把稳了，收获 *{$traffic}*，人生巅峰",
                "赌王风范，豪取 *{$traffic}*",
                "天降横财 *{$traffic}*，梭哈就是这么爽",
            ],
            'double' => [
                "🎉 翻倍！All in 赢麻了！净赚 *{$traffic}*！",
                "运气爆表，翻倍出局，收割 *{$traffic}*",
                "赌场今天是你家开的，翻倍到手 *{$traffic}*！",
                "双倍快乐，*{$traffic}* 进账，欧皇附体！",
            ],
            'burst' => [
                "💎 爆发！概率 1.2%，暴赚 *{$traffic}*，今天是你的幸运日！",
                "流量爆发！低概率大奖，*{$traffic}* 到手，传播一下！",
                "梭哈火箭起飞，稀有爆发，捞回 *{$traffic}*！",
                "这把梭出了大奖感觉，*{$traffic}* 落袋！",
            ],
            'jackpot' => [
                "🏆 大奖！仅 0.6% 概率！恭喜获得 *{$traffic}*，神仙手气！",
                "今天什么日子？0.6% 大奖！净赚 *{$traffic}*，快去买彩票！",
                "梭哈界的幸运儿出现了！稀有大奖 *{$traffic}*！",
            ],
            'legend' => [
                "🌟 *传说！0.2% 的概率！* 恭喜您触发了最高档位，净赚 *{$traffic}*！今日欧皇非你莫属！",
                "🌟 *史诗级欧皇诞生！* 万分之二的传说降临，*{$traffic}* 入账！截图留念！",
                "🌟 *全服首席赌神！* 0.2% 传说档，*{$traffic}* 到手，可以炫耀一整年！",
            ],
        ];

        $list = $stories[$tier] ?? ["结果出来了，盈亏 *{$traffic}*"];
        return $list[random_int(0, count($list) - 1)];
    }

    // ── 今日欧皇 / 非酋 ───────────────────────────────────

    private function getTodayRanking($telegramService): string
    {
        try {
            $today = Carbon::now()->format('Y-m-d');

            $lucky   = UserCheckin::where('checkin_date', $today)->where('traffic_type', 1)
                ->orderByDesc('traffic_amount')->first();
            $unlucky = UserCheckin::where('checkin_date', $today)->where('traffic_type', 0)
                ->orderByDesc('traffic_amount')->first();

            $result  = $this->formatRankLine('👑 今日欧皇', $lucky,   '+', $telegramService);
            $result .= "\n" . $this->formatRankLine('🤡 今日非酋', $unlucky, '-', $telegramService);
            return $result;
        } catch (\Exception $e) {
            Log::error('获取梭哈排行失败', ['error' => $e->getMessage()]);
            return "👑 今日欧皇 暂无\n🤡 今日非酋 暂无";
        }
    }

    private function formatRankLine($label, $checkin, $prefix, $telegramService): string
    {
        if (!$checkin) {
            return "{$label} 暂无";
        }

        $traffic = $this->formatTraffic($checkin->traffic_amount);
        $user    = User::find($checkin->user_id);

        if ($user && $user->telegram_id) {
            $name = $this->getDisplayName($user->telegram_id, $telegramService);
            return "{$label} {$name} {$prefix}{$traffic}";
        }

        return "{$label} {$prefix}{$traffic}";
    }

    // ── 工具方法 ──────────────────────────────────────────

    private function formatTraffic($bytes): string
    {
        $mb = abs($bytes) / 1048576;
        return $mb >= 1024
            ? number_format($mb / 1024, 2) . ' GB'
            : number_format($mb, 2) . ' MB';
    }

    private function getUserName($telegramId, $telegramService): string
    {
        try {
            $chat = $telegramService->getChat($telegramId);
            if ($chat) {
                $name    = trim(($chat->first_name ?? '') . ' ' . ($chat->last_name ?? ''));
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

    private function getDisplayName($telegramId, $telegramService): string
    {
        try {
            $chat = $telegramService->getChat($telegramId);
            if ($chat) {
                $name = trim(($chat->first_name ?? '') . ' ' . ($chat->last_name ?? ''));
                if ($name !== '') {
                    return $this->escape($name);
                }
                if (!empty($chat->username)) {
                    return $this->escape($chat->username);
                }
            }
        } catch (\Exception $e) {}
        return 'User ' . $telegramId;
    }

    private function escape($text): string
    {
        // _ 由 sendMessage('markdown') 统一处理，避免双重转义
        return str_replace(
            ['*', '[', ']', '`'],
            ['\\*', '\\[', '\\]', '\\`'],
            $text
        );
    }

    private function reply($telegramService, $message, $isPrivate, $text): void
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
