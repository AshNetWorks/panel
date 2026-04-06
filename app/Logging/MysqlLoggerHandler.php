<?php
namespace App\Logging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use App\Models\Log as LogModel;

class MysqlLoggerHandler extends AbstractProcessingHandler
{
    public function __construct($level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        try{
            if(isset($record['context']['exception']) && is_object($record['context']['exception'])){
                $record['context']['exception'] = (array)$record['context']['exception'];
            }
            $record['request_data'] = request()->all() ?? [];

            $contextJson = isset($record['context']) ? json_encode($record['context'], JSON_UNESCAPED_UNICODE) : '';
            // TEXT 列上限 64KB，截断超长 context 避免 insert 失败（migration 会将列改为 MEDIUMTEXT）
            if (mb_strlen($contextJson, '8bit') > 60000) {
                $contextJson = mb_substr($contextJson, 0, 60000) . '...(truncated)';
            }

            $log = [
                'title'      => mb_substr($record['message'], 0, 500),
                'level'      => $record['level_name'],
                'host'       => $record['request_host'] ?? request()->getSchemeAndHttpHost(),
                'uri'        => $record['request_uri'] ?? request()->getRequestUri(),
                'method'     => $record['request_method'] ?? request()->getMethod(),
                'ip'         => request()->getClientIp(),
                'data'       => json_encode($record['request_data']),
                'context'    => $contextJson,
                'created_at' => strtotime($record['datetime']),
                'updated_at' => strtotime($record['datetime']),
            ];

            LogModel::insert(
                $log
            );
        }catch (\Exception $e){
            Log::channel('daily')->error($e->getMessage().$e->getFile().$e->getTraceAsString());
        }
    }
}
