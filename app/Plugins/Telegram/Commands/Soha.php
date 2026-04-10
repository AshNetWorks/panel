<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Models\UserCheckin;
use App\Plugins\Telegram\Telegram;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class Soha extends Telegram
{
    public $command = '/soha';
    public $description = '流量梭哈，押注 5GB 拼手气，连败有安慰奖，彩池随时爆发（每日一次）';

    private const ENABLED = true;

    /** 押注量：5 GB */
    private const BET_AMOUNT = 5 * 1024 * 1024 * 1024;

    /** 彩池配置 */
    private const POOL_KEY  = 'soha:jackpot_pool';
    private const POOL_MAX  = 50 * 1024 * 1024 * 1024; // 50 GB 上限
    private const POOL_RATE = 0.05;                     // 每次亏损的 5% 进池

    /** 连败配置 */
    private const STREAK_PREFIX = 'soha:streak:';
    private const STREAK_TTL    = 30 * 86400; // 30 天过期

    /** 触发群公告的档位（爆发及以上） */
    private const ANNOUNCE_TIERS = ['burst', 'jackpot', 'pool_jackpot', 'legend'];

    /**
     * 概率分布表：[累计阈值(万分比), 档位key, 基础倍率, 浮动范围]
     *
     * 期望回报率 ≈ 72%（庄家留存约 28%）：
     *   22%   × 0      = 0
     *   18%   × 0.30   = 0.054
     *   14%   × 0.50   = 0.070
     *   13%   × 0.75   = 0.098
     *   11%   × 0.95   = 0.105
     *    9%   × 1.15   = 0.104
     *    6%   × 1.45   = 0.087
     *    4%   × 1.95   = 0.078
     *    2%   × 2.75   = 0.055
     *  0.80%  × 4.25   = 0.034
     *  0.10%  × 6.50   = 0.007
     *  0.05%  × pool   = 动态（彩池）
     *  0.05%  × 11.50  = 0.006
     *                  ≈ 0.698 + 彩池动态
     */
    private const TIERS = [
        [2200,  'total_loss',   0,    0   ],  // 22.00% 全输       0x
        [4000,  'big_loss',     0.2,  0.2 ],  // 18.00% 大亏       0.2–0.4x
        [5400,  'small_loss',   0.4,  0.2 ],  // 14.00% 小亏       0.4–0.6x
        [6700,  'near_break',   0.65, 0.2 ],  // 13.00% 差点回本   0.65–0.85x
        [7800,  'break_even',   0.9,  0.1 ],  // 11.00% 保本       0.9–1.0x
        [8700,  'small_win',    1.05, 0.2 ],  //  9.00% 小赚       1.05–1.25x
        [9300,  'medium_win',   1.3,  0.3 ],  //  6.00% 中赚       1.3–1.6x
        [9700,  'big_win',      1.7,  0.5 ],  //  4.00% 大赚       1.7–2.2x
        [9900,  'double',       2.5,  0.5 ],  //  2.00% 翻倍       2.5–3.0x
        [9980,  'burst',        3.5,  1.5 ],  //  0.80% 爆发       3.5–5.0x
        [9990,  'jackpot',      5.0,  3.0 ],  //  0.10% 大奖       5.0–8.0x
        [9995,  'pool_jackpot', 5.0,  3.0 ],  //  0.05% 彩池爆发   5.0–8.0x + 彩池全额
        [10000, 'legend',       8.0,  7.0 ],  //  0.05% 传说       8.0–15.0x
    ];

    private const TIER_META = [
        'total_loss'   => ['💀', '全输'],
        'big_loss'     => ['😵', '大亏'],
        'small_loss'   => ['💸', '小亏'],
        'near_break'   => ['😮‍💨', '差点回本'],
        'break_even'   => ['😐', '保本'],
        'small_win'    => ['😊', '小赚'],
        'medium_win'   => ['😄', '中赚'],
        'big_win'      => ['🎊', '大赚'],
        'double'       => ['🎉', '翻倍'],
        'burst'        => ['💎', '爆发'],
        'jackpot'      => ['🏆', '大奖'],
        'pool_jackpot' => ['💰', '彩池爆发'],
        'legend'       => ['🌟', '传说'],
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
                $poolAmount  = $this->getPoolAmount();
                $poolDisplay = $poolAmount > 0
                    ? "\n\n💰 *当前彩池*：" . $this->formatTraffic($poolAmount)
                    : '';
                return $this->reply($telegramService, $message, $isPrivate,
                    "✋ 您今天已经梭哈过了！\n\n⏰ 每日限一次，明天再来碰运气～{$poolDisplay}");
            }

            // 今日第几位
            $rank = UserCheckin::where('checkin_date', $today)
                ->distinct('user_id')->count('user_id') + 1;

            // 抽取档位
            $roll       = $this->rollMultiplier();
            $multiplier = $roll['multiplier'];
            $tier       = $roll['tier'];

            // 基础返还
            $baseReward = (int)(self::BET_AMOUNT * $multiplier);

            // 彩池爆发：发放全部彩池
            $poolBonus = 0;
            if ($tier === 'pool_jackpot') {
                $poolBonus = $this->consumePool();
            }

            $finalReward = $baseReward + $poolBonus;
            $netProfit   = $finalReward - self::BET_AMOUNT;

            // 连败 & 安慰奖 & 彩池贡献
            $streak      = 0;
            $consolation = 0;

            if ($netProfit < 0) {
                // 亏损：5% 进彩池，增加连败计数，检查安慰奖
                $this->addToPool((int)(abs($netProfit) * self::POOL_RATE));
                $streak      = $this->incrementStreak($user->id);
                $consolation = $this->getConsolation($streak);
                if ($consolation > 0) {
                    $finalReward += $consolation;
                    $netProfit   += $consolation;
                }
            } else {
                // 盈利或保本：记录当前连败数后重置
                $streak = $this->getStreak($user->id);
                $this->resetStreak($user->id);
            }

            // 调整已使用流量
            $user->d = max(0, $user->d - $netProfit);
            $user->save();

            // 记录签到
            UserCheckin::create([
                'user_id'        => $user->id,
                'checkin_date'   => $today,
                'traffic_amount' => abs($netProfit),
                'traffic_type'   => $netProfit >= 0 ? 1 : 0,
                'is_bonus'       => false,
                'created_at'     => time(),
            ]);

            $msg = $this->buildMessage(
                $user, $netProfit, $multiplier, $tier, $rank,
                $chatId, $telegramService,
                $poolBonus, $consolation, $streak
            );

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

            // 爆发及以上：无论私聊/群聊都在群里发公告（不自动删除）
            if (in_array($tier, self::ANNOUNCE_TIERS, true)) {
                $groupId = (int) config('v2board.telegram_group_id');
                if ($groupId) {
                    try {
                        $announcement = $this->buildAnnouncement(
                            $tier, $netProfit, $poolBonus, $chatId, $telegramService
                        );
                        $resp = $telegramService->sendMessage($groupId, $announcement, 'markdown');
                        // 发送成功后静默置顶该消息（不通知用户）
                        if ($resp && isset($resp->result->message_id)) {
                            $telegramService->pinChatMessage($groupId, $resp->result->message_id, true);
                        }
                    } catch (\Exception $e) {
                        Log::warning('梭哈大奖公告发送失败', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                    }
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

    // ── 彩池操作 ──────────────────────────────────────────

    private function getPoolAmount(): int
    {
        try {
            return (int)(Redis::get(self::POOL_KEY) ?? 0);
        } catch (\Exception $e) {
            Log::warning('彩池读取失败', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function addToPool(int $contribution): void
    {
        try {
            $current = $this->getPoolAmount();
            $new     = min($current + $contribution, self::POOL_MAX);
            Redis::set(self::POOL_KEY, $new);
        } catch (\Exception $e) {
            Log::warning('彩池写入失败', ['error' => $e->getMessage()]);
        }
    }

    private function consumePool(): int
    {
        try {
            $amount = $this->getPoolAmount();
            if ($amount > 0) {
                Redis::set(self::POOL_KEY, 0);
            }
            return $amount;
        } catch (\Exception $e) {
            Log::warning('彩池消耗失败', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    // ── 连败 & 安慰奖 ─────────────────────────────────────

    private function getStreak(int $userId): int
    {
        try {
            return (int)(Redis::get(self::STREAK_PREFIX . $userId) ?? 0);
        } catch (\Exception) {
            return 0;
        }
    }

    private function incrementStreak(int $userId): int
    {
        try {
            $key    = self::STREAK_PREFIX . $userId;
            $streak = (int)Redis::incr($key);
            Redis::expire($key, self::STREAK_TTL);
            return $streak;
        } catch (\Exception) {
            return 0;
        }
    }

    private function resetStreak(int $userId): void
    {
        try {
            Redis::del(self::STREAK_PREFIX . $userId);
        } catch (\Exception) {}
    }

    /**
     * 连败安慰奖：每满 3 连败发放一次
     *   第  3 败 → +0.5 GB
     *   第  6 败 → +1.0 GB
     *   第  9 败 → +1.5 GB
     *   第 12 败+ → +2.0 GB（封顶）
     */
    private function getConsolation(int $streak): int
    {
        if ($streak <= 0 || $streak % 3 !== 0) {
            return 0;
        }
        $level = min(intdiv($streak, 3), 4); // 最高 4 级
        return (int)($level * 0.5 * 1024 * 1024 * 1024);
    }

    // ── 消息构建 ──────────────────────────────────────────

    private function buildMessage(
        $user, $netProfit, $multiplier, $tier, $rank,
        $chatId, $telegramService,
        int $poolBonus, int $consolation, int $streak
    ): string {
        $meta = self::TIER_META[$tier] ?? ['🎲', '未知'];
        [$emoji, $tierLabel] = $meta;

        $userName    = $this->getUserName($chatId, $telegramService);
        $betDisplay  = $this->formatTraffic(self::BET_AMOUNT);
        $baseReward  = (int)(self::BET_AMOUNT * $multiplier);
        $finalReward = $baseReward + $poolBonus + $consolation;
        $profitSign  = $netProfit >= 0 ? '+' : '-';
        $profitDisplay = $profitSign . $this->formatTraffic(abs($netProfit));
        $rateDisplay = number_format($multiplier, 2) . 'x';

        // 标题
        $msg  = "{$emoji} *【{$tierLabel}】梭哈结算！*\n";
        $msg .= "👤 {$userName}\n";
        $msg .= "🎰 今日第 *{$rank}* 位梭哈\n\n";

        // 幽默故事
        $msg .= "📖 " . $this->getStory($tier, $this->formatTraffic(abs($netProfit))) . "\n";

        // 彩池爆发额外提示
        if ($poolBonus > 0) {
            $msg .= "\n🎊 *彩池大爆发！* 额外获得彩池奖励 *+" . $this->formatTraffic($poolBonus) . "*\n";
        }

        // 连败安慰奖提示
        if ($consolation > 0) {
            $msg .= "\n🤝 *连败安慰奖*（{$streak} 连败）：*+" . $this->formatTraffic($consolation) . "*\n";
        } elseif ($streak > 0 && $netProfit < 0) {
            // 亏损但未触发安慰奖：显示连败进度
            $nextMilestone = (int)(ceil($streak / 3) * 3);
            $remaining     = $nextMilestone - $streak;
            $nextLevel     = min(intdiv($nextMilestone, 3), 4);
            $nextBonus     = $this->formatTraffic((int)($nextLevel * 0.5 * 1024 * 1024 * 1024));
            $msg .= "\n💔 *连败 {$streak} 次*，再输 *{$remaining}* 次可获安慰奖 *+{$nextBonus}*\n";
        }

        // 投注详情
        $msg .= "\n💰 *投注详情*\n";
        $msg .= "• 投入：{$betDisplay}\n";
        $msg .= "• 倍率：*{$rateDisplay}*\n";
        $msg .= "• 返还：" . $this->formatTraffic($finalReward) . "\n";
        $msg .= "• 盈亏：*{$profitDisplay}*\n";

        // 当前流量
        $used  = $user->u + $user->d;
        $total = $user->transfer_enable;
        $msg  .= "\n📊 *当前流量*\n";
        $msg  .= "• 已用：" . $this->formatTraffic($used) . "\n";
        $msg  .= "• 剩余：" . $this->formatTraffic($total - $used) . "\n";
        $msg  .= "• 总计：" . $this->formatTraffic($total) . "\n\n";

        // 今日排行 + 彩池
        $msg .= $this->getTodayRanking($telegramService);

        return $msg;
    }

    // ── 群公告（大奖专用）────────────────────────────────

    private function buildAnnouncement(
        string $tier, int $netProfit, int $poolBonus,
        int $chatId, $telegramService
    ): string {
        $emoji    = (self::TIER_META[$tier] ?? ['🎲'])[0];
        $userName = $this->getUserName($chatId, $telegramService);
        $gainAmount = $this->formatTraffic(abs($netProfit));

        $lines = [
            'burst'        => "恭喜 {$userName} 触发 *爆发* 档位，大赚 *{$gainAmount}*！手气爆棚，今日欧皇候选！",
            'jackpot'      => "恭喜 {$userName} 命中 *大奖* 档位，净赚 *{$gainAmount}*！神仙手气，快来膜拜！",
            'pool_jackpot' => "恭喜 {$userName} 触发 *彩池爆发*，独吞彩池 *+" . $this->formatTraffic($poolBonus) . "*，总到手 *{$gainAmount}*！全服见证历史时刻！",
            'legend'       => "恭喜 {$userName} 触发 *传说* 最高档位，净赚 *{$gainAmount}*！史诗级欧皇降临，截图留念！",
        ];

        $line = $lines[$tier] ?? "恭喜 {$userName} 大赚 *{$gainAmount}*！";

        return "{$emoji}{$emoji}{$emoji}\n{$line}\n{$emoji}{$emoji}{$emoji}";
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
                "翻倍！All in 赢麻了！净赚 *{$traffic}*！",
                "运气爆表，翻倍出局，收割 *{$traffic}*",
                "赌场今天是你家开的，翻倍到手 *{$traffic}*！",
                "双倍快乐，*{$traffic}* 进账，欧皇附体！",
            ],
            'burst' => [
                "爆发！稀有档位触发，暴赚 *{$traffic}*，今天是你的幸运日！",
                "流量爆发！低概率大奖，*{$traffic}* 到手，传播一下！",
                "梭哈火箭起飞，稀有爆发，捞回 *{$traffic}*！",
                "这把梭出了大奖感觉，*{$traffic}* 落袋！",
            ],
            'jackpot' => [
                "大奖！极低概率命中！恭喜获得 *{$traffic}*，神仙手气！",
                "今天什么日子？大奖！净赚 *{$traffic}*，快去买彩票！",
                "梭哈界的幸运儿出现了！稀有大奖 *{$traffic}*！",
            ],
            'pool_jackpot' => [
                "彩池爆发！众人积累的彩池被你一锅端，带走了 *{$traffic}*！",
                "恭喜触发彩池！万众期待的大奖砸中了你，总计 *{$traffic}* 入账！",
                "彩池被清空！梭哈史上的高光时刻，*{$traffic}* 全部属于你！",
            ],
            'legend' => [
                "*传说！* 恭喜您触发最高档位，净赚 *{$traffic}*！今日欧皇非你莫属！",
                "*史诗级欧皇诞生！* 传说降临，*{$traffic}* 入账！截图留念！",
                "*全服首席赌神！* 传说档，*{$traffic}* 到手，可以炫耀一整年！",
            ],
        ];

        $list = $stories[$tier] ?? ["结果出来了，盈亏 *{$traffic}*"];
        return $list[random_int(0, count($list) - 1)];
    }

    // ── 今日欧皇 / 非酋 + 彩池 ───────────────────────────

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
            $result .= "\n💰 当前彩池：*" . $this->formatTraffic($this->getPoolAmount()) . "*";

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
        } catch (\Exception) {
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
        } catch (\Exception) {}
        return 'User ' . $telegramId;
    }

    private function escape($text): string
    {
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
