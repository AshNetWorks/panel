<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) abort(500, 'verify error');
            if (!$this->handle($verify['trade_no'], $verify['callback_no'])) {
                abort(500, 'handle error');
            }
            return(isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            abort(500, 'fail');
        }
    }

    private function handle($tradeNo, $callbackNo)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            abort(500, 'order is not found');
        }
        if ($order->status !== 0) return true;
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }
        $telegramService = new TelegramService();

        $user   = User::find($order->user_id);
        $plan   = Plan::find($order->plan_id);
        $typeMap = [1 => '新购套餐', 2 => '续费套餐', 3 => '升级套餐'];
        $typeStr = $typeMap[$order->type] ?? '购买';

        $periodMap = [
            'month_price'      => '1个月',
            'quarter_price'    => '3个月',
            'half_year_price'  => '6个月',
            'year_price'       => '1年',
            'two_year_price'   => '2年',
            'three_year_price' => '3年',
            'onetime_price'    => '一次性',
            'reset_price'      => '重置流量',
            'deposit'          => '充值',
        ];
        $periodStr = $periodMap[$order->period] ?? ($order->period ?? '未知');

        $paidAmount    = number_format($order->total_amount / 100, 2);
        $balance       = $user ? number_format($user->balance / 100, 2) : '0.00';
        $commBalance   = $user ? number_format($user->commission_balance / 100, 2) : '0.00';
        $expiredAt     = ($user && $user->expired_at) ? date('Y-m-d', $user->expired_at) : '长期有效';
        $planName      = $plan ? $plan->name : '未知套餐';
        $email         = $user ? $user->email : '未知';

        // 用户下单时记录的 IP 及归属地
        $clientIp     = $order->client_ip ?? '';
        $ipLine       = $clientIp ? "\n来访 IP：{$clientIp}" : '';
        $locationLine = '';
        if ($order->client_country) {
            $loc = trim(($order->client_province ?? '') . ' ' . ($order->client_city ?? ''));
            $locationLine = "\n用户位置：{$loc}，{$order->client_country}";
        }

        $message = "财神收款：{$paidAmount} 元💰\n" .
                   "新购套餐：{$planName}\n" .
                   "用户邮箱：{$email}\n" .
                   "购买类型：{$typeStr}\n" .
                   "购买周期：{$periodStr}\n" .
                   "———————————————\n" .
                   "余额/佣金余额：{$balance} / {$commBalance}\n" .
                   "到期时间：{$expiredAt}" .
                   $locationLine .
                   $ipLine . "\n" .
                   "订单号：{$order->trade_no}";

        $telegramService->sendMessageWithAdmin($message);
        return true;
    }
}
