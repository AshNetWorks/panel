<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Models\UserCheckin;
use App\Models\UserCheckinStats;
use App\Plugins\Telegram\Telegram;
use Carbon\Carbon;

class Checkin extends Telegram
{
    public $command = '/checkin';
    public $description = '每日签到，可选倍数 1-10（例如：/checkin 5）';

    /**
     * 功能开关：true=启用签到，false=关闭签到（引导用户去 /soha）
     */
    private const ENABLED = false;

    public function handle($message, $match = [])
    {
        try {
            $telegramService = $this->telegramService;

            // 区分私聊和群组
            $isPrivate = $message->is_private;
            $chatId = $isPrivate ? $message->chat_id : $message->from_id;

            // 功能开关检查
            if (!self::ENABLED) {
                $text = "📢 签到功能已关闭，请使用 /soha 进行流量梭哈！";
                if ($isPrivate) {
                    $telegramService->sendMessage($message->chat_id, $text, 'markdown');
                } else {
                    $telegramService->sendMessageWithAutoDelete($message->chat_id, $text, 'markdown', 60, $message->message_id);
                }
                return;
            }

        // 解析押注倍数（从命令文本中提取）
        $multiplier = 1; // 默认1倍
        if (isset($message->text)) {
            $parts = explode(' ', trim($message->text));
            if (count($parts) >= 2) {
                $inputMultiplier = intval($parts[1]);
                // 限制倍数范围 1-10
                if ($inputMultiplier >= 1 && $inputMultiplier <= 10) {
                    $multiplier = $inputMultiplier;
                } elseif ($inputMultiplier > 10) {
                    $multiplier = 10;
                } elseif ($inputMultiplier < 1) {
                    $multiplier = 1;
                }
            }
        }

        $user = User::where('telegram_id', $chatId)->first();
        if (!$user) {
            $text = '没有查询到您的用户信息，请先绑定账号';
            if ($isPrivate) {
                $telegramService->sendMessage($message->chat_id, $text, 'markdown');
            } else {
                $telegramService->sendMessageWithAutoDelete($message->chat_id, $text, 'markdown', 60, $message->message_id);
            }
            return;
        }

        // 检查用户套餐是否有效
        if (!$this->canCheckin($user)) {
            $text = "❌ *签到失败*\n\n";
            $text .= "您的套餐已过期或未激活，无法签到。\n\n";

            if ($user->expired_at) {
                $expiredDate = Carbon::createFromTimestamp($user->expired_at)->format('Y-m-d H:i:s');
                $text .= "📅 套餐过期时间：{$expiredDate}\n\n";
            }

            $text .= "💡 请续费套餐后再进行签到。";

            if ($isPrivate) {
                $telegramService->sendMessage($message->chat_id, $text, 'markdown');
            } else {
                $telegramService->sendMessageWithAutoDelete($message->chat_id, $text, 'markdown', 60, $message->message_id);
            }
            return;
        }

        $today = Carbon::now()->format('Y-m-d');

        // 获取或创建签到统计
        $stats = UserCheckinStats::firstOrCreate(
            ['user_id' => $user->id],
            [
                'last_checkin_date' => null,
                'last_checkin_at' => null,
                'checkin_streak' => 0,
                'total_checkin_days' => 0,
                'total_checkin_traffic' => 0
            ]
        );

        // 检查今天是否已签到
        if ($stats->last_checkin_date && $stats->last_checkin_date->format('Y-m-d') === $today) {
            $text = "✋ 您今天已经签到过了！\n\n⏰ 请明天再来签到吧～";
            if ($isPrivate) {
                $telegramService->sendMessage($message->chat_id, $text, 'markdown');
            } else {
                $telegramService->sendMessageWithAutoDelete($message->chat_id, $text, 'markdown', 60, $message->message_id);
            }
            return;
        }

        // 计算今日签到排名（在保存签到记录之前统计）
        $todayCheckinRank = UserCheckin::where('checkin_date', $today)
            ->distinct('user_id')
            ->count('user_id') + 1; // +1 因为当前用户还未签到

        // 计算连续签到天数
        $lastCheckinDate = $stats->last_checkin_date ? $stats->last_checkin_date->format('Y-m-d') : null;
        $yesterday = Carbon::now()->subDay()->format('Y-m-d');

        if ($lastCheckinDate === $yesterday) {
            // 连续签到
            $stats->checkin_streak += 1;
        } else {
            // 不是连续签到，重置为1
            $stats->checkin_streak = 1;
        }

        // 使用优化后的签到系统生成奖励/惩罚
        // 新系统特点：
        // - 50% 奖励 / 50% 惩罚（严格平衡）
        // - 3级系统：小奖励25%，中奖励20%，大奖励5%
        // - 套餐分级：根据实际套餐进行合理分级
        // - 浮动上限：1.5%套餐大小 ±200-500MB随机浮动
        // - 隐形突破：1%概率无视上限（15-25GB）
        // - 幸运时刻：1%概率1.5-2倍加成

        // 根据用户套餐流量动态调整奖励
        $rewardResult = $this->generateCryptoRandomReward($user, $multiplier);
        $trafficType = $rewardResult['type'];
        $trafficBytes = $rewardResult['traffic'];
        $rewardTier = $rewardResult['tier'];
        $isLucky = $rewardResult['is_lucky'];

        // 检查是否连续签到满7天，给予双倍（奖励和惩罚都翻倍）
        $isBonus = false;
        $bonusText = '';
        if ($stats->checkin_streak % 7 === 0) {
            $trafficBytes *= 2;
            $isBonus = true;
            if ($trafficType === 1) {
                $bonusText = '🎊 *连续签到7天，双倍奖励！*';
            } else {
                $bonusText = '⚠️ *连续签到7天，双倍惩罚！*';
            }
        }

        if ($trafficType === 1) {
            // 减少已用流量（相当于增加可用流量）
            $reduction = min($trafficBytes, $user->u + $user->d);

            if ($user->u >= $reduction) {
                $user->u -= $reduction;
            } else {
                $remainingReduction = $reduction - $user->u;
                $user->u = 0;
                $user->d = max(0, $user->d - $remainingReduction);
            }

            $trafficDisplay = $this->formatTraffic($reduction);

            // 随机奖励消息（幽默故事版 - 🌟为幸运时刻专属）
            $rewardMessages = [
                "🎁 因为你的笑容太灿烂，流量天使送来了 *{$trafficDisplay}*",
                "🎉 恭喜！你被流量之神选中，获得从天而降的 *{$trafficDisplay}*",
                "✨ 路边捡到锦鲤，锦鲤吐出了 *{$trafficDisplay}*",
                "🎇 因为今天是个好日子，宇宙馈赠给你 *{$trafficDisplay}*",
                "🎊 流量精灵觉得你很可爱，偷偷塞给你 *{$trafficDisplay}*",
                "💎 挖地三尺发现宝藏，获得闪闪发光的 *{$trafficDisplay}*",
                "🎈 抓住一只流量气球，里面装着 *{$trafficDisplay}*",
                "🍀 踩到四叶草触发好运，天降 *{$trafficDisplay}*",
                "🎯 签到暴击！触发三倍经验，收获 *{$trafficDisplay}*",
                "🏆 今日最佳幸运儿就是你！奖励 *{$trafficDisplay}*",
                "🎪 流量马戏团正在演出，观众席掉落 *{$trafficDisplay}*",
                "🎭 命运女神眨了眨眼，变出了 *{$trafficDisplay}*",
                "🎨 画了个流量大饼，没想到成真了 *{$trafficDisplay}*",
                "🎵 唱歌跑调引来流量鸟，它送你 *{$trafficDisplay}*",
                "🎸 空气吉他弹得太棒，观众打赏 *{$trafficDisplay}*",
                "🌈 追着彩虹跑到尽头，捡到 *{$trafficDisplay}*",
                "🚀 坐火箭路过流量星球，顺手捞了 *{$trafficDisplay}*",
                "🍰 许愿蜡烛吹灭了，愿望实现获得 *{$trafficDisplay}*",
                "🎮 游戏人生通关成功，系统奖励 *{$trafficDisplay}*",
                "⚡ 被幸运闪电劈中，意外充能 *{$trafficDisplay}*"
            ];

            $rewardEmojis = ['🎁', '🎉', '✨', '🎇', '🎊', '💎', '🎈', '🍀', '🎯', '🏆', '🎪', '🎭', '🎨', '🎵', '🎸', '🌈', '🚀', '🍰', '🎮', '⚡'];
            
            // 使用已有的随机值来选择消息
            $randomBytes = random_bytes(2);
            $randomIndex = unpack('n', $randomBytes)[1] % count($rewardMessages);
            
            $emoji = $rewardEmojis[$randomIndex];
            $actionText = $rewardMessages[$randomIndex];
            
            // 如果触发幸运时刻，添加特殊提示
            if ($isLucky) {
                $actionText = "🌟 *触发幸运时刻！* " . $actionText;
            }
            
            $actualTraffic = $reduction; // 实际减少的流量
        } else {
            // 增加已用流量（相当于减少可用流量）
            // 惩罚直接执行，无保护限制（体现惩罚的真实效果）
            $actualPenalty = $trafficBytes;

            $user->u += $actualPenalty;
            $trafficDisplay = $this->formatTraffic($actualPenalty);

            // 随机惩罚消息（幽默故事版）
            $penaltyMessages = [
                "💸 流量小偷趁你不注意，偷走了 *{$trafficDisplay}*",
                "😅 因为今天手气太背，流量君离家出走了 *{$trafficDisplay}*",
                "🙈 流量精灵打了个哈欠，不小心吹飞了 *{$trafficDisplay}*",
                "😬 走路不看路踩到香蕉皮，摔掉了 *{$trafficDisplay}*",
                "🤷‍♂️ 流量说要去看世界，带走了 *{$trafficDisplay}*",
                "😵 黑洞路过觉得很饿，吃掉了你的 *{$trafficDisplay}*",
                "🫠 因为天气太热，流量融化蒸发了 *{$trafficDisplay}*",
                "😓 一阵妖风吹过，卷走了 *{$trafficDisplay}*",
                "🥲 流量跟你玩捉迷藏，藏起来了 *{$trafficDisplay}*",
                "😭 流浪猫咪路过，叼走了 *{$trafficDisplay}*",
                "🤦‍♂️ 流量说今天不想上班，罢工了 *{$trafficDisplay}*",
                "😵‍💫 流量喝醉了迷路，走丢了 *{$trafficDisplay}*",
                "🙃 踩到流量陷阱，掉进去 *{$trafficDisplay}*",
                "😪 流量睡懒觉睡过头，迟到扣了 *{$trafficDisplay}*",
                "🤧 流量感冒发烧请病假，消耗了 *{$trafficDisplay}*",
                "🌪️ 龙卷风经过你家门口，卷走了 *{$trafficDisplay}*",
                "🎭 流量去好莱坞追梦，带走了 *{$trafficDisplay}*",
                "🎲 跟流量恶魔赌骰子输了，赔上 *{$trafficDisplay}*",
                "🍃 秋风扫落叶般无情，带走了 *{$trafficDisplay}*",
                "⏰ 因为你起床太晚，流量罚你 *{$trafficDisplay}*"
            ];

            $penaltyEmojis = ['💸', '😅', '🙈', '😬', '🤷‍♂️', '😵', '🫠', '😓', '🥲', '😭', '🤦‍♂️', '😵‍💫', '🙃', '😪', '🤧', '🌪️', '🎭', '🎲', '🍃', '⏰'];
            
            // 使用随机值来选择消息
            $randomBytes = random_bytes(2);
            $randomIndex = unpack('n', $randomBytes)[1] % count($penaltyMessages);
            
            $emoji = $penaltyEmojis[$randomIndex];
            $actionText = $penaltyMessages[$randomIndex];
            $actualTraffic = $actualPenalty; // 实际增加的流量
        }

        // 保存用户流量变化
        $user->save();

        // 获取请求信息（用于防作弊检测）
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // 创建签到记录
        UserCheckin::create([
            'user_id' => $user->id,
            'checkin_date' => $today,
            'traffic_amount' => $actualTraffic,
            'traffic_type' => $trafficType,
            'is_bonus' => $isBonus,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'created_at' => time()
        ]);

        // 更新签到统计
        $stats->last_checkin_date = Carbon::now();
        $stats->last_checkin_at = time();
        $stats->total_checkin_days += 1;

        // 根据 traffic_type 统计累计流量（包括奖励和扣除）
        // traffic_type = 0: 增加已用流量（扣除），减少统计值
        // traffic_type = 1: 减少已用流量（奖励），增加统计值
        // total_checkin_traffic 表示累计净获得的流量（可以为负数）
        if ($trafficType === 1) {
            // 奖励流量，累加到统计
            $stats->total_checkin_traffic += $actualTraffic;
        } else {
            // 扣除流量，从统计中减去
            $stats->total_checkin_traffic -= $actualTraffic;
        }

        $stats->updated_at = time();
        $stats->save();

        // 清理30天前的旧记录
        $this->cleanOldRecords();

        // 获取今日欧皇和非酋
        $todayRanking = $this->getTodayRanking($telegramService);

        // 获取用户的 Telegram 名称
        $userName = $this->getTelegramUserName($chatId, $telegramService);

        // 计算当前流量使用情况
        $usedTrafficBytes = $user->u + $user->d;
        $totalTrafficBytes = $user->transfer_enable;
        $remainingTrafficBytes = $totalTrafficBytes - $usedTrafficBytes;

        $msg = "{$emoji} *签到成功！*\n";
        $msg .= "👤 {$userName}\n";
        $msg .= "🏆 今日第 *{$todayCheckinRank}* 名签到\n\n";

        // 显示押注倍数
        if ($multiplier > 1) {
            $msg .= "🎯 押注倍数：*{$multiplier}x*\n";
        }

        // 如果有双倍奖励，显示特别提示
        if ($bonusText) {
            $msg .= "{$bonusText}\n\n";
        } else {
            $msg .= "\n";
        }

        $msg .= "🎲 本次签到：{$actionText}\n";
        $msg .= "🔥 连续签到：*{$stats->checkin_streak}* 天";

        // 显示距离下次7天奖励还差几天
        if ($stats->checkin_streak < 7) {
            $daysToBonus = 7 - $stats->checkin_streak;
            $msg .= "（再签到{$daysToBonus}天获得双倍奖励）";
        } elseif ($stats->checkin_streak % 7 !== 0) {
            $daysToBonus = 7 - ($stats->checkin_streak % 7);
            $msg .= "（再签到{$daysToBonus}天获得双倍奖励）";
        }

        $msg .= "\n\n📊 *当前流量状态*\n";
        $msg .= "• 已用流量：" . $this->formatTraffic($usedTrafficBytes) . "\n";
        $msg .= "• 剩余流量：" . $this->formatTraffic($remainingTrafficBytes) . "\n";
        $msg .= "• 总计流量：" . $this->formatTraffic($totalTrafficBytes) . "\n\n";
        $msg .= $todayRanking;

        if ($isPrivate) {
            // 私聊直接发送
            $telegramService->sendMessage($message->chat_id, $msg, 'markdown');
        } else {
            // 群组中发送60秒自动删除的消息
            $telegramService->sendMessageWithAutoDelete($message->chat_id, $msg, 'markdown', 60, $message->message_id);

            // 如果触发了幸运星，单独发一条不自动删除的群组消息
            if ($isLucky) {
                try {
                    $luckyMsg = "🌟✨ *恭喜* {$userName} *触发幸运星！* ✨🌟\n\n";
                    $luckyMsg .= "🎊 本次签到获得了超级奖励！\n";
                    $luckyMsg .= "🎁 奖励流量：*{$trafficDisplay}*\n\n";
                    $luckyMsg .= "💫 这是千载难逢的幸运时刻！";

                    // 发送不自动删除的群组消息
                    $telegramService->sendMessage($message->chat_id, $luckyMsg, 'markdown');
                } catch (\Exception $e) {
                    \Log::warning('发送幸运星群组消息失败', [
                        'user_id' => $user->id,
                        'telegram_id' => $chatId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // 同时私发详细信息给用户
            try {
                $privateMsg = "{$emoji} *签到成功！*（来自群组）\n";
                $privateMsg .= "👤 {$userName}\n";
                $privateMsg .= "🏆 今日第 *{$todayCheckinRank}* 名签到\n\n";

                // 显示押注倍数
                if ($multiplier > 1) {
                    $privateMsg .= "🎯 押注倍数：*{$multiplier}x*\n";
                }

                if ($bonusText) {
                    $privateMsg .= "{$bonusText}\n\n";
                } else {
                    $privateMsg .= "\n";
                }

                $privateMsg .= "🎲 本次签到：{$actionText}\n";
                $privateMsg .= "🔥 连续签到：*{$stats->checkin_streak}* 天";

                if ($stats->checkin_streak < 7) {
                    $daysToBonus = 7 - $stats->checkin_streak;
                    $privateMsg .= "（再签到{$daysToBonus}天获得双倍奖励）";
                } elseif ($stats->checkin_streak % 7 !== 0) {
                    $daysToBonus = 7 - ($stats->checkin_streak % 7);
                    $privateMsg .= "（再签到{$daysToBonus}天获得双倍奖励）";
                }

                $privateMsg .= "\n\n📊 *当前流量状态*\n";
                $privateMsg .= "• 已用流量：" . $this->formatTraffic($usedTrafficBytes) . "\n";
                $privateMsg .= "• 剩余流量：" . $this->formatTraffic($remainingTrafficBytes) . "\n";
                $privateMsg .= "• 总计流量：" . $this->formatTraffic($totalTrafficBytes) . "\n\n";
                $privateMsg .= $todayRanking;

                // 发送私聊消息给用户
                $telegramService->sendMessage($chatId, $privateMsg, 'markdown');
            } catch (\Exception $e) {
                // 如果私发失败（用户可能未与机器人开启私聊），记录日志但不影响群组签到
                \Log::warning('群组签到后私发消息失败', [
                    'user_id' => $user->id,
                    'telegram_id' => $chatId,
                    'error' => $e->getMessage()
                ]);
            }
        }
        } catch (\Exception $e) {
            // 捕获所有异常，记录日志并通知用户
            \Log::error('签到功能发生错误', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            // 通知用户签到失败
            $errorMsg = "❌ 签到失败！\n\n";
            $errorMsg .= "发生了一个错误，请稍后重试。\n";
            $errorMsg .= "如果问题持续，请联系管理员。\n\n";
            $errorMsg .= "错误信息：" . $e->getMessage();

            try {
                if (isset($isPrivate) && isset($message)) {
                    if ($isPrivate) {
                        $telegramService->sendMessage($message->chat_id, $errorMsg, 'markdown');
                    } else {
                        $telegramService->sendMessageWithAutoDelete($message->chat_id, $errorMsg, 'markdown', 60, $message->message_id);
                    }
                }
            } catch (\Exception $sendError) {
                \Log::error('发送错误消息失败', ['error' => $sendError->getMessage()]);
            }
        }
    }

    /**
     * 检查用户是否可以签到
     * 条件：有套餐、套餐未过期、账户状态正常
     */
    private function canCheckin($user)
    {
        // 必须有套餐 ID
        if (!$user->plan_id) {
            return false;
        }

        // 必须有过期时间且未过期
        if (!$user->expired_at) {
            return false;
        }

        $expiredAt = is_numeric($user->expired_at)
            ? Carbon::createFromTimestamp($user->expired_at)
            : Carbon::parse($user->expired_at);

        if (!$expiredAt->isFuture()) {
            return false;
        }

        // 账户状态必须为正常（1=正常，0=禁用）
        if ($user->banned == 1) {
            return false;
        }

        return true;
    }

    /**
     * 格式化流量显示
     * 大于 1024MB 时显示为 GB，否则显示为 MB
     */
    private function formatTraffic($bytes)
    {
        $mb = $bytes / 1048576; // 转换为 MB

        if ($mb >= 1024) {
            // 大于等于 1024MB，转换为 GB
            $gb = $mb / 1024;
            return number_format($gb, 2) . ' GB';
        } else {
            // 小于 1024MB，显示为 MB
            return number_format($mb, 2) . ' MB';
        }
    }

    /**
     * 通过 Telegram ID 获取用户名
     * 显示 first_name + last_name，如果有 username 则创建超链接
     */
    private function getTelegramUserName($telegramId, $telegramService)
    {
        if (!$telegramId) {
            return '未知用户';
        }

        try {
            $chat = $telegramService->getChat($telegramId);

            if ($chat) {
                // 构建显示名称：first_name + last_name
                $displayName = '';
                if (!empty($chat->first_name)) {
                    $displayName = $chat->first_name;
                }
                if (!empty($chat->last_name)) {
                    $displayName .= ' ' . $chat->last_name;
                }
                $displayName = trim($displayName);

                // 如果有 username，创建超链接
                if (!empty($chat->username)) {
                    // Markdown 格式：[显示名称](链接)
                    $escapedName = $this->escapeMarkdown($displayName ?: $chat->username);
                    return "[{$escapedName}](tg://user?id={$telegramId})";
                }

                // 没有 username，只显示名称
                if ($displayName) {
                    return "[{$this->escapeMarkdown($displayName)}](tg://user?id={$telegramId})";
                }
            }
        } catch (\Exception $e) {
            \Log::error('获取 Telegram 用户信息失败', [
                'telegram_id' => $telegramId,
                'error' => $e->getMessage()
            ]);
        }

        // 如果获取失败，返回 ID
        return 'User ' . $telegramId;
    }

    /**
     * 通过 Telegram ID 获取用户显示名称（纯文本，不含链接）
     * 用于欧皇/非酋显示，只显示 first_name，不进行 @ 提及
     */
    private function getTelegramDisplayName($telegramId, $telegramService)
    {
        if (!$telegramId) {
            return '未知用户';
        }

        try {
            $chat = $telegramService->getChat($telegramId);

            if ($chat) {
                // 只返回 first_name，仅转义必要的 Markdown 字符
                if (!empty($chat->first_name)) {
                    return $this->escapeName($chat->first_name);
                }

                // 如果没有 first_name，尝试使用 username
                if (!empty($chat->username)) {
                    return $this->escapeName($chat->username);
                }
            }
        } catch (\Exception $e) {
            \Log::error('获取 Telegram 用户显示名称失败', [
                'telegram_id' => $telegramId,
                'error' => $e->getMessage()
            ]);
        }

        // 如果获取失败，返回 ID
        return 'User ' . $telegramId;
    }

    /**
     * 转义名称中的特殊字符（仅转义会破坏格式的字符）
     */
    private function escapeName($text)
    {
        // 只转义会影响 Markdown 解析的关键字符
        $escapeChars = ['_', '*', '[', ']', '`'];
        foreach ($escapeChars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }
        return $text;
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

    /**
     * 清理30天前的签到记录
     */
    private function cleanOldRecords()
    {
        try {
            $thirtyDaysAgo = Carbon::now()->subDays(30)->format('Y-m-d');
            UserCheckin::where('checkin_date', '<', $thirtyDaysAgo)->delete();
        } catch (\Exception $e) {
            \Log::error('清理签到记录失败', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 获取今日欧皇和非酋
     * 返回今天获得流量最多（欧皇）和被扣流量最多（非酋）的用户
     */
    private function getTodayRanking($telegramService)
    {
        try {
            $today = Carbon::now()->format('Y-m-d');
            $result = "";

            // 查询今日欧皇：获得奖励流量最多的用户（traffic_type = 1）
            $luckyCheckin = UserCheckin::where('checkin_date', $today)
                ->where('traffic_type', 1) // 只查询奖励类型
                ->orderBy('traffic_amount', 'desc')
                ->first();

            if ($luckyCheckin) {
                $luckyUser = User::find($luckyCheckin->user_id);
                if ($luckyUser && $luckyUser->telegram_id) {
                    $luckyUserName = $this->getTelegramDisplayName($luckyUser->telegram_id, $telegramService);
                    $luckyTraffic = $this->formatTraffic($luckyCheckin->traffic_amount);
                    $result .= "👑 今日欧皇 {$luckyUserName} +{$luckyTraffic}";
                } else {
                    $result .= "👑 今日欧皇 +" . $this->formatTraffic($luckyCheckin->traffic_amount);
                }
            } else {
                $result .= "👑 今日欧皇 暂无";
            }

            // 查询今日非酋：被扣除流量最多的用户（traffic_type = 0）
            $unluckyCheckin = UserCheckin::where('checkin_date', $today)
                ->where('traffic_type', 0) // 只查询惩罚类型
                ->orderBy('traffic_amount', 'desc')
                ->first();

            if ($unluckyCheckin) {
                $unluckyUser = User::find($unluckyCheckin->user_id);
                if ($unluckyUser && $unluckyUser->telegram_id) {
                    $unluckyUserName = $this->getTelegramDisplayName($unluckyUser->telegram_id, $telegramService);
                    $unluckyTraffic = $this->formatTraffic($unluckyCheckin->traffic_amount);
                    $result .= "\n🤡 今日非酋 {$unluckyUserName} -{$unluckyTraffic}";
                } else {
                    $result .= "\n🤡 今日非酋 -" . $this->formatTraffic($unluckyCheckin->traffic_amount);
                }
            } else {
                $result .= "\n🤡 今日非酋 暂无";
            }

            return $result;
        } catch (\Exception $e) {
            \Log::error('获取今日欧皇/非酋失败', [
                'error' => $e->getMessage()
            ]);
            return "👑 今日欧皇 暂无\n🤡 今日非酋 暂无";
        }
    }

    /**
     * 使用加密级随机源生成奖励/惩罚（固定流量范围版本）
     *
     * 【核心设计理念】
     * - 简化概率：50% 奖励 / 50% 惩罚
     * - 3级系统：小奖励25%，中奖励20%，大奖励5%（奖励和惩罚各自分级）
     * - 固定流量范围：所有用户使用相同的流量范围（公平性）
     * - 上限控制：10倍押注后最多3-5GB（幸运时刻不受此限制）
     * - 幸运时刻：仅在奖励时有1%概率触发，1.5-2倍加成，不受上限限制
     * - 用户界面：只显示"奖励流量 XXX"或"扣除流量 XXX"，隐藏所有等级信息
     *
     * 【固定流量范围】（所有用户统一标准）
     * 基础倍数(1倍)的流量范围：
     *   - 小奖励 25%：50-150MB
     *   - 中奖励 20%：150-300MB
     *   - 大奖励 5%：300-500MB
     *   - 小惩罚 25%：30-100MB
     *   - 中惩罚 20%：100-200MB
     *   - 大惩罚 5%：200-400MB
     *
     * 【流量计算顺序】
     * 1. 判断奖励/惩罚（50%/50%）
     * 2. 如果是奖励，检查幸运时刻（1%概率）⭐ 仅奖励时触发
     * 3. 生成基础流量（50-500MB）
     * 4. 应用押注倍数（×1-10）
     * 5. 应用固定上限（3-5GB随机）⭐ 普通模式到此为止
     * 6. 应用幸运加成（×1.5-2.0）⭐ 幸运时刻不受上限限制
     * 7. 应用7天双倍（×2，在主函数中）
     *
     * 【最大奖励示例】
     * - 普通10倍押注: 5GB → 上限3-5GB → 7天双倍 = 6-10GB
     * - 幸运10倍押注: 5GB → 上限3-5GB → 幸运×2 = 6-10GB → 7天双倍 = 12-20GB ⭐
     *
     * 【幸运时刻触发概率】
     * - 总签到概率: 100%
     * - 奖励概率: 50%
     * - 奖励中触发幸运: 1%
     * - 实际触发概率: 50% × 1% = 0.5% (每200次签到约1次) ⭐
     *
     * @param User $user 用户对象
     * @param int $multiplier 押注倍数
     * @return array ['type' => int, 'traffic' => int, 'tier' => string, 'is_lucky' => bool]
     */
    private function generateCryptoRandomReward($user, $multiplier = 1)
    {
        // 使用 random_bytes 获取加密级随机字节
        $randomBytes = random_bytes(4);
        $randomValue = unpack('L', $randomBytes)[1];
        $probability = ($randomValue % 10000) / 100; // 0-99.99

        $type = 1; // 默认奖励
        $tier = 'reward'; // 简化等级标识
        $minBytes = 0;
        $maxBytes = 0;
        $isLucky = false; // 默认非幸运时刻

        // 固定 50% 概率奖励，50% 概率惩罚
        if ($probability < 40.0) {
            // ========== 奖励 50% ==========
            $type = 1;
            $tier = 'reward';
            $rewardRoll = ($probability / 50.0) * 100; // 归一化到 0-100

            // 检查是否触发幸运时刻（仅在奖励时，1%概率）
            $luckyBytes = random_bytes(2);
            $luckyValue = unpack('n', $luckyBytes)[1];
            $isLucky = ($luckyValue % 100) === 0; // 1/100 = 1%

            if ($rewardRoll < 25.0) {
                // 小奖励 25%：50-150MB
                $minBytes = 50 * 1048576;
                $maxBytes = 150 * 1048576;
            } elseif ($rewardRoll < 45.0) {
                // 中奖励 20%：150-300MB
                $minBytes = 150 * 1048576;
                $maxBytes = 300 * 1048576;
            } else {
                // 大奖励 5%：300-500MB
                $minBytes = 300 * 1048576;
                $maxBytes = 500 * 1048576;
            }
        } else {
            // ========== 惩罚 50% ==========
            $type = 0;
            $tier = 'penalty';
            $penaltyRoll = (($probability - 50.0) / 50.0) * 100; // 归一化到 0-100
            // 惩罚时不触发幸运时刻
            $isLucky = false;

            if ($penaltyRoll < 25.0) {
                // 小惩罚 25%：30-100MB
                $minBytes = 30 * 1048576;
                $maxBytes = 100 * 1048576;
            } elseif ($penaltyRoll < 45.0) {
                // 中惩罚 20%：100-200MB
                $minBytes = 100 * 1048576;
                $maxBytes = 200 * 1048576;
            } else {
                // 大惩罚 5%：200-400MB
                $minBytes = 200 * 1048576;
                $maxBytes = 400 * 1048576;
            }
        }

        // 使用 random_bytes 在范围内生成流量值
        $range = $maxBytes - $minBytes;
        if ($range > 0) {
            $trafficRandomBytes = random_bytes(4);
            $trafficRandom = unpack('L', $trafficRandomBytes)[1];
            $trafficBytes = $minBytes + ($trafficRandom % $range);
        } else {
            $trafficBytes = $minBytes;
        }

        // 应用押注倍数（添加溢出保护）
        if ($multiplier > 1) {
            // 检查是否会溢出
            if ($trafficBytes > PHP_INT_MAX / $multiplier) {
                // 如果会溢出，使用安全的最大值
                $trafficBytes = PHP_INT_MAX;
            } else {
                $trafficBytes *= $multiplier;
            }
        }

        // 应用固定上限：3-5GB随机浮动（幸运时刻不受此限制）
        // 使用新的随机字节生成3-5GB之间的随机上限
        $capRandomBytes = random_bytes(4);
        $capRandomValue = unpack('L', $capRandomBytes)[1];

        $minCap = 3 * 1073741824; // 3GB
        $maxCap = 5 * 1073741824; // 5GB
        $capRange = $maxCap - $minCap;
        $finalCap = $minCap + ($capRandomValue % $capRange);

        // 应用上限
        $beforeCap = $trafficBytes;
        $trafficBytes = min($trafficBytes, $finalCap);
        $capped = ($beforeCap > $finalCap);

        // 应用幸运时刻加成（1.5-2倍）- 在上限之后应用，不受上限限制
        if ($isLucky) {
            $luckyMultiplier = 1.5 + (($luckyValue % 50) / 100.0); // 1.5-2.0倍随机
            // 添加溢出保护
            if ($trafficBytes > PHP_INT_MAX / $luckyMultiplier) {
                $trafficBytes = PHP_INT_MAX;
            } else {
                $trafficBytes = (int)($trafficBytes * $luckyMultiplier);
            }
        }

        // 记录上限信息
        $capInfo = [
            'cap_type' => 'fixed',
            'cap_gb' => round($finalCap / 1073741824, 2),
            'capped' => $capped,
            'before_cap_gb' => round($beforeCap / 1073741824, 2),
            'after_lucky_gb' => $isLucky ? round($trafficBytes / 1073741824, 2) : null
        ];

        return [
            'type' => $type,
            'traffic' => $trafficBytes,
            'tier' => $tier,
            'is_lucky' => $isLucky
        ];
    }
}
