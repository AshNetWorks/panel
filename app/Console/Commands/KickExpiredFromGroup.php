<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class KickExpiredFromGroup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:kick-expired
                            {--group-id= : 群组ID（默认使用配置值）}
                            {--grace=0 : 宽限小时数，到期后多少小时再踢（默认0）}
                            {--notify : 踢出时发送私信通知用户}
                            {--dry-run : 演习模式，只检测不执行踢出}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '自动踢出套餐已到期的 Telegram 群组成员（踢出不拉黑，续费后可重新 /join）';

    /**
     * @var TelegramService
     */
    protected $telegramService;

    public function __construct()
    {
        parent::__construct();
        $this->telegramService = new TelegramService();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!config('v2board.telegram_bot_enable', 0)) {
            $this->warn('Telegram Bot 未启用，跳过执行');
            return;
        }

        $groupId   = (int) ($this->option('group-id') ?: config('v2board.telegram_group_id'));
        $graceHours = (int) $this->option('grace');
        $withNotify = (bool) $this->option('notify');
        $dryRun     = (bool) $this->option('dry-run');

        $expiredBefore = time() - ($graceHours * 3600);

        if ($dryRun) {
            $this->info('[演习模式] 不会执行实际踢出操作');
        }

        $this->info("群组ID: {$groupId}");
        $this->info("宽限: {$graceHours} 小时（到期超过 {$graceHours}h 才踢）");
        $this->line('---');

        // 需要踢出的条件（三选一，只要绑定了 telegram_id）：
        //   1. 套餐已到期（含宽限期）
        //   2. 账号被封禁
        //   3. 无套餐（plan_id = null）
        // 注意：真正「未绑定账号」但混入群组的成员（DB 中无记录），
        //       Telegram Bot API 无法枚举群成员，须通过 join 事件追踪，此处无法处理。
        $query = User::whereNotNull('telegram_id')
            ->where(function ($q) use ($expiredBefore) {
                $q->where(function ($q2) use ($expiredBefore) {
                        // 套餐已到期
                        $q2->whereNotNull('expired_at')
                           ->where('expired_at', '<', $expiredBefore);
                    })
                    ->orWhere('banned', 1)    // 封禁账号
                    ->orWhereNull('plan_id'); // 无套餐
            });

        $total = $query->count();

        if ($total === 0) {
            $this->info('没有符合条件的用户（无过期/封禁/无套餐）');
            return;
        }

        $this->info("找到 {$total} 个需要检查的用户，开始扫描群组成员状态...");
        $this->line('');

        $kicked      = 0;
        $skipped     = 0;
        $notInGroup  = 0;
        $errors      = 0;
        $kickedUsers = []; // 记录成功踢出的用户，用于事后通知管理员

        // chunk(200) 分批处理，避免全量加载占内存
        $query->orderBy('id')
            ->chunk(200, function ($expiredUsers) use (
                $groupId, $dryRun, $withNotify, $expiredBefore,
                &$kicked, &$skipped, &$notInGroup, &$errors, &$kickedUsers
            ) {
        foreach ($expiredUsers as $user) {
            $telegramId = (int) $user->telegram_id;
            $email      = $user->email;

            // 判断踢出原因（用于日志和通知）
            if ($user->banned) {
                $reason = '封禁';
            } elseif ($user->plan_id === null) {
                $reason = '无套餐';
            } else {
                $reason = '到期:' . date('Y-m-d', $user->expired_at);
            }

            // 检查用户是否在群组中
            try {
                $member = $this->telegramService->getChatMember($groupId, $telegramId);
            } catch (\Exception $e) {
                Log::error('KickExpired: getChatMember 异常', [
                    'telegram_id' => $telegramId,
                    'email'       => $email,
                    'error'       => $e->getMessage(),
                ]);
                $errors++;
                continue;
            }

            // 不在群组中，跳过
            if (!$member || !in_array($member->status ?? '', ['member', 'administrator', 'creator', 'restricted'])) {
                $this->line("  ⬛ {$email} (TG:{$telegramId}) 不在群组中，跳过");
                $notInGroup++;
                usleep(200000); // 200ms，避免频繁 API 请求
                continue;
            }

            // 管理员/群主不踢
            if (in_array($member->status ?? '', ['administrator', 'creator'])) {
                $this->line("  🔰 {$email} (TG:{$telegramId}) 是管理员，跳过");
                $skipped++;
                usleep(200000);
                continue;
            }

            // 执行踢出
            $this->line("  🔄 踢出 {$email} (TG:{$telegramId}) 原因:{$reason}");

            if ($dryRun) {
                $this->info("     [演习] 将被踢出（不执行）");
                $kicked++;
                continue;
            }

            try {
                $result = $this->telegramService->kickChatMember($groupId, $telegramId);

                if ($result) {
                    $this->info("     ✅ 踢出成功");
                    $kicked++;
                    $kickedUsers[] = ['email' => $email, 'telegram_id' => $telegramId, 'reason' => $reason];

                    Log::info('KickExpired: 踢出用户', [
                        'email'       => $email,
                        'telegram_id' => $telegramId,
                        'reason'      => $reason,
                        'group_id'    => $groupId,
                    ]);

                    // 发送通知
                    if ($withNotify) {
                        $this->sendExpireNotice($telegramId, $user, $reason);
                    }
                } else {
                    $this->warn("     ❌ 踢出失败（API返回失败）");
                    $errors++;
                }
            } catch (\Exception $e) {
                $this->warn("     ❌ 踢出异常: " . $e->getMessage());
                Log::error('KickExpired: 踢出异常', [
                    'email'       => $email,
                    'telegram_id' => $telegramId,
                    'error'       => $e->getMessage(),
                ]);
                $errors++;
            }

            // 每次踢出后等待1秒，避免触发 Telegram 频率限制
            sleep(1);
        }
        }); // end chunk

        $this->line('');
        $this->line('===== 执行结果 =====');
        $this->info("踢出: {$kicked}");
        $this->line("不在群组: {$notInGroup}");
        $this->line("跳过(管理员): {$skipped}");
        if ($errors > 0) {
            $this->warn("失败: {$errors}");
        }

        Log::info('KickExpired: 执行完成', [
            'group_id'    => $groupId,
            'total'       => $total,
            'kicked'      => $kicked,
            'not_in_group'=> $notInGroup,
            'skipped'     => $skipped,
            'errors'      => $errors,
            'dry_run'     => $dryRun,
        ]);

        // 通知管理员
        if (!$dryRun && !empty($kickedUsers)) {
            $this->notifyAdmins($kickedUsers, $groupId, $errors);
        }
    }

    /**
     * 向所有管理员发送踢出汇总报告（HTML 模式，直接调用避免 SendTelegramJob 的 markdown 限制）
     */
    private function notifyAdmins(array $kickedUsers, int $groupId, int $errors): void
    {
        $admins = User::where('is_admin', 1)
            ->whereNotNull('telegram_id')
            ->get();

        if ($admins->isEmpty()) return;

        $date  = date('Y-m-d H:i');
        $count = count($kickedUsers);

        $header = "🚫 <b>群组踢出报告</b>\n"
                . "━━━━━━━━━━━━━━━━\n"
                . "🕐 时间：{$date}\n"
                . "👥 共踢出：<b>{$count}</b> 人\n";

        if ($errors > 0) {
            $header .= "❌ 失败：{$errors} 人\n";
        }

        $lines = [];
        foreach ($kickedUsers as $u) {
            $lines[] = "• " . htmlspecialchars($u['email'])
                     . "（" . htmlspecialchars($u['reason']) . "）";
        }

        $detail  = implode("\n", $lines);
        $message = $header . "\n<b>踢出明细：</b>\n" . $detail;

        // 超过 4000 字符时只保留汇总行
        if (mb_strlen($message) > 4000) {
            $message = $header . "\n<i>（明细过长已省略）</i>";
        }

        foreach ($admins as $admin) {
            try {
                $this->telegramService->sendMessage((int) $admin->telegram_id, $message, 'html');
                usleep(300000); // 300ms 间隔，避免频率限制
            } catch (\Exception $e) {
                Log::warning('KickExpired: 发送管理员通知失败', [
                    'telegram_id' => $admin->telegram_id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 向被踢用户发送私信通知
     */
    private function sendExpireNotice(int $telegramId, User $user, string $reason): void
    {
        try {
            if ($user->banned) {
                $message = "🚪 <b>您已被移出群组</b>\n\n" .
                           "您的账号已被封禁，系统已自动将您移出群组。\n\n" .
                           "如有疑问请联系客服。";
            } elseif ($user->plan_id === null) {
                $message = "🚪 <b>您已被移出群组</b>\n\n" .
                           "您当前没有有效套餐，系统已自动将您移出群组。\n\n" .
                           "✅ <b>购买套餐后可立即重新加入：</b>\n" .
                           "1. 前往用户中心购买套餐\n" .
                           "2. 购买成功后，私聊机器人发送 /join\n" .
                           "3. 点击邀请链接即可重新入群\n\n" .
                           "如有疑问请联系客服。";
            } else {
                $expiredAt = date('Y-m-d', $user->expired_at);
                $message = "🚪 <b>您已被移出群组</b>\n\n" .
                           "您的套餐已于 <b>{$expiredAt}</b> 到期，系统已自动将您移出群组。\n\n" .
                           "✅ <b>续费后可立即重新加入：</b>\n" .
                           "1. 前往用户中心完成套餐续费\n" .
                           "2. 续费成功后，私聊机器人发送 /join\n" .
                           "3. 点击邀请链接即可重新入群\n\n" .
                           "踢出不拉黑，续费后随时可重新加入。\n\n" .
                           "如有疑问请联系客服。";
            }

            $this->telegramService->sendMessage($telegramId, $message, 'html');
        } catch (\Exception $e) {
            Log::warning('KickExpired: 发送通知失败', [
                'telegram_id' => $telegramId,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
