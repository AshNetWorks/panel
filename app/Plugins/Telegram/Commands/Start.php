<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class Start extends Telegram
{
    public $command = '/start';
    public $description = '开始使用机器人';

    public function handle($message, $match = [])
    {
        if (!$message->is_private) return;

        // 处理深链接绑定：/start TOKEN（来自面板跳转链接 t.me/bot?start=TOKEN）
        $startToken = $message->args[0] ?? null;
        if ($startToken) {
            $token = $startToken;
            // 移除伪装后缀（若配置了）
            $disguiseSuffix = config('v2board.subscribe_url_suffix');
            if ($disguiseSuffix && substr($token, -strlen($disguiseSuffix)) === $disguiseSuffix) {
                $token = substr($token, 0, -strlen($disguiseSuffix));
            }
            $bindUser = User::where('token', $token)->first();
            if ($bindUser) {
                if ($bindUser->telegram_id) {
                    $this->telegramService->sendMessage($message->chat_id, '❌ 该账号已绑定了 Telegram 账号，请先解绑后重试。');
                    return;
                }
                $bindUser->telegram_id = $message->chat_id;
                if ($bindUser->save()) {
                    $this->telegramService->sendMessage($message->chat_id, '✅ 绑定成功！发送 /start 查看账户信息。');
                    return;
                }
                $this->telegramService->sendMessage($message->chat_id, '❌ 绑定失败，请稍后重试。');
                return;
            }
            // token 无效时不中断，继续走正常 /start 流程
        }

        $user = User::where('telegram_id', $message->chat_id)->first();
        
        if (!$user) {
            $welcomeText = "👋 欢迎使用 Telegram Bot！\n";
            $welcomeText .= "━━━━━━━━━━━━━━━━\n\n";
            $welcomeText .= "❌ 您尚未绑定账户\n\n";
            $welcomeText .= "📝 *绑定步骤：*\n";
            $welcomeText .= "1️⃣ 访问用户中心\n";
            $welcomeText .= "2️⃣ 复制您的订阅链接\n";
            $welcomeText .= "3️⃣ 发送 `/bind 订阅链接`\n";
            $welcomeText .= "4️⃣ 绑定成功后重新发送 /start\n\n";
            $welcomeText .= "🎉 *绑定后可用功能：*\n";
            $welcomeText .= "• 📊 流量查询与统计\n";
            $welcomeText .= "• 🚪 内部群组邀请\n";
            $welcomeText .= "• 🔔 每日流量通知\n";
            $welcomeText .= "• 🔄 订阅拉取通知\n\n";
            $welcomeText .= "❓ 发送 /help 查看更多帮助";
        } else {
            $appName = config('v2board.app_name', 'V2Board');

            $welcomeText = "🎉 欢迎回来！\n";
            $welcomeText .= "━━━━━━━━━━━━━━━━\n\n";
            $welcomeText .= "✅ 账户已绑定\n";
            $welcomeText .= "📧 " . $user->email . "\n\n";

            $welcomeText .= "📋 *常用命令：*\n";
            $welcomeText .= "• /traffic — 查看流量使用情况\n";
            $welcomeText .= "• /status — 查看账户状态与到期时间\n";
            $welcomeText .= "• /subscribe — 获取订阅链接\n";
            $welcomeText .= "• /join — 获取群组邀请链接\n";
            $welcomeText .= "• /checkin — 每日签到领取流量奖励\n";
            $welcomeText .= "• /soha — 流量梭哈押注抽奖（每日一次）\n";
            $welcomeText .= "• /notify — 流量通知管理\n";
            $welcomeText .= "• /help — 查看完整命令帮助\n\n";

            $welcomeText .= "⚙️ *当前设置：*\n";
            $notifyStatus = $user->telegram_daily_traffic_notify ? '🟢 已开启' : '🔴 已关闭';
            $notifyTime = $user->telegram_notify_time ?? '23:50';
            $subscribeNotifyStatus = $user->telegram_subscribe_notify ? '🟢 已开启' : '🔴 已关闭';

            $welcomeText .= "流量通知：{$notifyStatus}";
            if ($user->telegram_daily_traffic_notify) {
                $welcomeText .= " ({$notifyTime})";
            }
            $welcomeText .= "\n";
            $welcomeText .= "订阅通知：{$subscribeNotifyStatus}\n\n";
            $welcomeText .= "💬 有问题？发送 /help 获取帮助";

            if ($user->is_admin) {
                $welcomeText .= "\n\n━━━━━━━━━━━━━━━━\n";
                $welcomeText .= "🔑 *管理员命令*\n";
                $welcomeText .= "• /stats — 系统用户概况\n";
                $welcomeText .= "• /userinfo [邮箱/ID/tg:ID] — 查询用户详情\n";
                $welcomeText .= "• /grant\_days [天数] all|[邮箱] [留言] — 赠送天数\n";
                $welcomeText .= "• /grant\_traffic [GB] all|[邮箱] [留言] — 赠送流量\n";
                $welcomeText .= "• /set\_rate [倍率] [关键词] — 调整节点倍率\n";
                $welcomeText .= "• /ban [邮箱] — 封禁用户\n";
                $welcomeText .= "• /unban [邮箱] — 解封用户\n";
                $welcomeText .= "• /watch add|remove|list — 监控名单管理\n";
                $welcomeText .= "发送 /help 查看详细说明";
            }
        }

        $this->telegramService->sendMessage($message->chat_id, $welcomeText);
    }
}