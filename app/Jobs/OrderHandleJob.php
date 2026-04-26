<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\EmbyService;
use App\Services\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderHandleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $tradeNo;

    public $tries = 3;
    public $timeout = 60;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($tradeNo)
    {
        $this->onQueue('order_handle');
        $this->tradeNo = $tradeNo;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $order = Order::where('trade_no', $this->tradeNo)
            ->first();

        if (!$order) return;

        $orderService = new OrderService($order);
        switch ($order->status) {
            case 0:
                if ($order->created_at <= (time() - 3600 * 2)) {
                    $orderService->cancel();
                }
                break;
            case 1:
                $orderService->open();
                $this->syncEmbyIfNeeded($order->user_id);
                break;
        }
    }

    private function syncEmbyIfNeeded(int $userId): void
    {
        try {
            $exists = DB::table('v2_emby_users')->where('user_id', $userId)->exists();
            if (!$exists) return;
            app(EmbyService::class)->syncUserExpiration($userId);
        } catch (\Throwable $e) {
            Log::error('OrderHandleJob: Emby sync failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
}