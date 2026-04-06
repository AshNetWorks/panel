<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class Help extends Telegram
{
    public $command = '/help';
    public $description = '显示帮助信息';

    public function handle($message, $match = [])
    {
        if (!$message->is_private) return;

        $user = User::where('telegram_id', $message->chat_id)->first();

        if (!$user) {
            $helpText = "📖 <b>Bot 使用帮助</b>\n";
            $helpText .= "━━━━━━━━━━━━━━━━\n\n";
            $helpText .= "❌ 您尚未绑定账户\n\n";
            $helpText .= "📝 <b>如何绑定：</b>\n";
            $helpText .= "1️⃣ 访问用户中心\n";
            $helpText .= "2️⃣ 复制您的订阅链接\n";
            $helpText .= "3️⃣ 发送 <code>/bind 订阅链接</code> 进行绑定\n";
            $helpText .= "4️⃣ 绑定成功后发送 /start 开始使用\n\n";
            $helpText .= "🎁 <b>绑定后可用功能：</b>\n";
            $helpText .= "• 📊 实时流量查询\n";
            $helpText .= "• 🚪 群组邀请链接\n";
            $helpText .= "• 🔔 每日流量通知\n";
            $helpText .= "• 🔄 订阅拉取通知\n";
            $helpText .= "• 📈 账户状态查询\n\n";
            $helpText .= "❓ 绑定遇到问题？请联系客服";

            $this->telegramService->sendMessage($message->chat_id, $helpText, 'html');
            return;
        }

        $helpText = "📖 <b>Bot 命令帮助</b>\n";
        $helpText .= "━━━━━━━━━━━━━━━━\n\n";

        $helpText .= "📊 <b>账户查询</b>\n";
        $helpText .= "• /traffic — 查看流量使用情况\n";
        $helpText .= "• /status — 查看账户状态与到期时间\n";
        $helpText .= "• /subscribe — 获取订阅链接\n\n";

        $helpText .= "🚪 <b>群组</b>\n";
        $helpText .= "• /join — 获取群组邀请链接\n";
        $helpText .= "  <i>套餐有效时可用，链接24小时有效且一次性</i>\n\n";

        $helpText .= "🔔 <b>通知设置</b>\n";
        $helpText .= "• /notify — 查看当前通知状态\n";
        $helpText .= "• /notify on — 开启每日流量通知\n";
        $helpText .= "• /notify off — 关闭每日流量通知\n";
        $helpText .= "• /notify HH:MM — 设置通知时间（如 <code>/notify 23:50</code>）\n\n";

        $helpText .= "🔄 <b>订阅通知</b>\n";
        $helpText .= "• /subscribe_notify on | off — 开关订阅拉取通知\n";
        $helpText .= "  开启后每次客户端拉取订阅时收到通知（含IP归属地）\n\n";

        $helpText .= "🎲 <b>签到 &amp; 娱乐</b>\n";
        $helpText .= "• /checkin — 每日签到（随机增减已用流量，50% 概率）\n";
        $helpText .= "• /checkin_rank — 查看签到排行榜（连续7天双倍奖励）\n";
        $helpText .= "• /soha — 流量梭哈，押注 5 GB 拼手气，连败有安慰奖，彩池随时爆发（每日一次）\n\n";

        $helpText .= "🔧 <b>账户管理</b>\n";
        $helpText .= "• /bind — 绑定订阅账户\n";
        $helpText .= "• /unbind — 解除账户绑定\n";
        $helpText .= "• /start — 查看快速入口\n";
        $helpText .= "• /help — 显示此帮助\n\n";

        // 当前设置状态
        $subscribeNotifyStatus = $user->telegram_subscribe_notify ? '🟢 已开启' : '🔴 已关闭';
        $notifyStatus = $user->telegram_daily_traffic_notify ? '🟢 已开启' : '🔴 已关闭';
        $notifyTime = $user->telegram_notify_time ?? '23:50';

        $helpText .= "⚙️ <b>当前设置</b>\n";
        $helpText .= "流量通知：{$notifyStatus}";
        if ($user->telegram_daily_traffic_notify) {
            $helpText .= " ({$notifyTime})";
        }
        $helpText .= "\n订阅通知：{$subscribeNotifyStatus}\n";

        // 管理员专属命令
        if ($user->is_admin) {
            $helpText .= "\n";
            $helpText .= "━━━━━━━━━━━━━━━━\n";
            $helpText .= "🔑 <b>管理员命令</b>（仅私聊可用）\n\n";

            $helpText .= "📈 <b>用户查询</b>\n";
            $helpText .= "• /stats — 查看系统用户概况统计\n";
            $helpText .= "• /userinfo [邮箱] — 查询指定用户详情\n\n";

            $helpText .= "🎁 <b>赠送资源</b>\n";
            $helpText .= "• /grant_days [天数] — 赠送所有有效用户天数\n";
            $helpText .= "• /grant_days [天数] [邮箱] — 赠送指定用户天数\n";
            $helpText .= "• /grant_days [天数] all|[邮箱] [留言] — 附言（可选）\n";
            $helpText .= "• /grant_traffic [GB] — 赠送所有有效用户本周期流量\n";
            $helpText .= "• /grant_traffic [GB] [邮箱] — 赠送指定用户本周期流量\n";
            $helpText .= "• /grant_traffic [GB] all|[邮箱] [留言] — 附言（可选）\n";
            $helpText .= "  <i>流量重置时自动还原为套餐标准值；用户收到通知时会显示前后对比</i>\n\n";

            $helpText .= "⚡ <b>节点管理</b>\n";
            $helpText .= "• /set_rate [倍率] [关键词] — 按关键词批量调整节点倍率\n";
            $helpText .= "  <i>示例：/set_rate 1.5 香港</i>\n\n";

            $helpText .= "🚫 <b>用户管理</b>\n";
            $helpText .= "• /ban [邮箱] — 封禁用户\n";
            $helpText .= "• /unban [邮箱] — 解封用户\n";
            $helpText .= "• /subunban [邮箱|ID] — 解除用户订阅封禁\n\n";

            $helpText .= "👁 <b>监控名单</b>\n";
            $helpText .= "• /watch add [邮箱] — 加入监控名单\n";
            $helpText .= "• /watch add [邮箱] [备注] — 加入并附注说明\n";
            $helpText .= "• /watch remove [邮箱] — 移出监控名单\n";
            $helpText .= "• /watch list — 查看当前监控名单\n";
            $helpText .= "  <i>触发通知：拉取订阅、购买/续费，以及曾用同 IP 的其他账号的相同操作</i>\n";
        }

        $this->telegramService->sendMessage($message->chat_id, $helpText, 'html');
    }
}
