<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\SendTelegramJob;
use MaxMind\Db\Reader as MmdbReader;

class UpdateGeoLite2 extends Command
{
    protected $signature = 'geoip:update';
    protected $description = '下载并更新 GeoLite2 IP 数据库（ASN / City / Country）';

    private const FILES = [
        'GeoLite2-ASN.mmdb'     => 'https://raw.githubusercontent.com/adysec/IP_database/main/geolite/GeoLite2-ASN.mmdb',
        'GeoLite2-City.mmdb'    => 'https://raw.githubusercontent.com/adysec/IP_database/main/geolite/GeoLite2-City.mmdb',
        'GeoLite2-Country.mmdb' => 'https://raw.githubusercontent.com/adysec/IP_database/main/geolite/GeoLite2-Country.mmdb',
    ];

    private const DEST_DIR = 'app/geoip';

    public function handle(): int
    {
        $destDir = storage_path(self::DEST_DIR);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $this->info('开始更新 GeoLite2 数据库...');

        $failed = [];
        foreach (self::FILES as $filename => $url) {
            $ok = $this->downloadAndReplace($filename, $url, $destDir);
            if (!$ok) {
                $failed[] = $filename;
            }
        }

        $successCount = count(self::FILES) - count($failed);
        $this->line('');
        $this->info("更新完成：{$successCount}/" . count(self::FILES) . " 个文件成功");

        if (!empty($failed)) {
            $this->error('失败文件：' . implode(', ', $failed));
            $this->notifyAdmin(false, $failed);
            return 1;
        }

        $this->notifyAdmin(true, []);
        return 0;
    }

    /**
     * 下载单个文件并原子替换旧文件
     * 下载到临时文件 → 用 MmdbReader 验证 → rename 替换
     */
    private function downloadAndReplace(string $filename, string $url, string $destDir): bool
    {
        $finalPath = $destDir . '/' . $filename;
        $tmpPath   = $destDir . '/' . $filename . '.tmp';

        $this->info("  下载 {$filename}...");

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 GeoLite2-Updater/1.0',
            ]);
            $data     = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($data === false || $data === '' || $httpCode !== 200) {
                $this->error("  ✗ {$filename} 下载失败（HTTP {$httpCode}）: {$curlErr}");
                Log::error('GeoLite2 下载失败', [
                    'file'      => $filename,
                    'http_code' => $httpCode,
                    'error'     => $curlErr,
                ]);
                return false;
            }

            // 写入临时文件
            file_put_contents($tmpPath, $data);
            unset($data); // 释放内存

            // 用 MmdbReader 验证文件完整性
            $reader = new MmdbReader($tmpPath);
            $reader->close();

            // 原子替换（rename 在同分区上是原子操作）
            rename($tmpPath, $finalPath);

            $sizeMB = round(filesize($finalPath) / 1024 / 1024, 1);
            $this->info("  ✓ {$filename} 更新成功（{$sizeMB} MB）");
            Log::info('GeoLite2 更新成功', ['file' => $filename, 'size_mb' => $sizeMB]);
            return true;

        } catch (\Exception $e) {
            $this->error("  ✗ {$filename} 处理失败: " . $e->getMessage());
            Log::error('GeoLite2 处理失败', ['file' => $filename, 'error' => $e->getMessage()]);
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
            return false;
        }
    }

    /**
     * 发送 Telegram 通知给管理员
     */
    private function notifyAdmin(bool $success, array $failed): void
    {
        try {
            $telegramId = $this->getAdminTelegramId();
            if (!$telegramId) {
                return;
            }

            if ($success) {
                $message  = "✅ GeoLite2 IP 数据库更新成功\n";
                $message .= "📅 更新时间：" . Carbon::now()->format('Y-m-d H:i:s') . "\n";
                $message .= "📦 文件：ASN / City / Country";
            } else {
                $message  = "❌ GeoLite2 IP 数据库更新失败\n";
                $message .= "📅 时间：" . Carbon::now()->format('Y-m-d H:i:s') . "\n";
                $message .= "⚠️ 失败：" . implode(', ', $failed);
            }

            SendTelegramJob::dispatch($telegramId, $message);
        } catch (\Exception $e) {
            Log::error('GeoLite2 更新通知发送失败', ['error' => $e->getMessage()]);
        }
    }

    private function getAdminTelegramId(): ?string
    {
        try {
            $admin = DB::table('v2_user')
                ->where('is_admin', 1)
                ->whereNotNull('telegram_id')
                ->where('banned', 0)
                ->first();
            return $admin?->telegram_id ?? null;
        } catch (\Exception $e) {
            Log::error('获取管理员 Telegram ID 失败', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
