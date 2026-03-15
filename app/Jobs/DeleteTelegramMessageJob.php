<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteTelegramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $chatId;
    protected $messageId;

    /**
     * Create a new job instance.
     */
    public function __construct($chatId, $messageId)
    {
        $this->chatId = $chatId;
        $this->messageId = $messageId;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        try {
            $telegramService = new TelegramService();
            $telegramService->deleteMessage($this->chatId, $this->messageId);

            \Log::info('自动删除Telegram消息', [
                'chat_id' => $this->chatId,
                'message_id' => $this->messageId
            ]);
        } catch (\Exception $e) {
            \Log::error('删除Telegram消息失败', [
                'chat_id' => $this->chatId,
                'message_id' => $this->messageId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
