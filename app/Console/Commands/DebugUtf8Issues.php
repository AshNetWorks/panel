<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DebugUtf8Issues extends Command
{
    protected $signature = 'debug:utf8-issues 
                            {--table=v2_subscribe_pull_log : 要检查的表名}
                            {--date= : 检查指定日期的数据}
                            {--limit=50 : 检查的记录数量}
                            {--fix : 是否修复发现的问题}';

    protected $description = '检查和修复数据库中的 UTF-8 编码问题';

    public function handle()
    {
        $tableName = $this->option('table');
        $date = $this->option('date');
        $limit = $this->option('limit');
        $shouldFix = $this->option('fix');

        $this->info("开始检查表 {$tableName} 中的 UTF-8 编码问题...");

        if ($date) {
            $targetDate = Carbon::createFromFormat('Y-m-d', $date);
            $this->info("检查日期: {$targetDate->format('Y-m-d')}");
        }

        try {
            // 先检查表是否存在
            if (!$this->tableExists($tableName)) {
                $this->error("表 {$tableName} 不存在");
                return 1;
            }

            // 检查表结构
            $this->checkTableStructure($tableName);
            
            // 检查数据
            $this->checkTableData($tableName, $date, $limit, $shouldFix);

        } catch (\Exception $e) {
            $this->error("检查失败: " . $e->getMessage());
            $this->error("错误追踪: " . $e->getTraceAsString());
            return 1;
        }

        return 0;
    }

    private function tableExists($tableName)
    {
        try {
            $exists = DB::select("SHOW TABLES LIKE '{$tableName}'");
            return !empty($exists);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkTableStructure($tableName)
    {
        $this->line("\n=== 检查表结构 ===");
        
        try {
            $columns = DB::select("SHOW FULL COLUMNS FROM {$tableName}");
            
            $this->info("表 {$tableName} 的字符串字段:");
            
            foreach ($columns as $column) {
                if (strpos($column->Type, 'char') !== false || 
                    strpos($column->Type, 'text') !== false) {
                    
                    $collation = $column->Collation ?: 'NULL';
                    
                    if (strpos($collation, 'utf8mb4') === false && $collation !== 'NULL') {
                        $this->warn("  {$column->Field} ({$column->Type}) - {$collation} ⚠️");
                    } else {
                        $this->info("  {$column->Field} ({$column->Type}) - {$collation} ✓");
                    }
                }
            }
            
        } catch (\Exception $e) {
            $this->error("无法检查表结构: " . $e->getMessage());
        }
    }

    private function checkTableData($tableName, $date, $limit, $shouldFix)
    {
        $this->line("\n=== 检查数据内容 ===");
        
        try {
            $query = DB::table($tableName);
            
            if ($date) {
                $targetDate = Carbon::createFromFormat('Y-m-d', $date);
                $query->whereDate('created_at', $targetDate);
                $this->info("筛选日期: {$targetDate->format('Y-m-d')}");
            } else {
                // 如果没指定日期，只检查最近的数据
                $query->orderBy('id', 'desc');
                $this->info("检查最近的 {$limit} 条记录");
            }
            
            $records = $query->limit($limit)->get();
            
            if ($records->isEmpty()) {
                $this->warn("没有找到匹配的记录");
                return;
            }
            
            $this->info("找到 {$records->count()} 条记录，开始检查...");
            
            $problematicRecords = [];
            $textFields = $this->getTextFields($tableName);
            
            $this->info("检查字段: " . implode(', ', $textFields));
            
            foreach ($records as $index => $record) {
                $issues = [];
                
                foreach ($textFields as $field) {
                    if (isset($record->$field) && !empty($record->$field)) {
                        $value = $record->$field;
                        
                        // 检查 UTF-8 编码
                        if (!mb_check_encoding($value, 'UTF-8')) {
                            $issues[] = "字段 {$field} 包含无效 UTF-8";
                        }
                        
                        // 检查控制字符
                        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                            $issues[] = "字段 {$field} 包含控制字符";
                        }
                        
                        // 检查可能的问题字符
                        if (preg_match('/[\x80-\xFF]/', $value) && !mb_check_encoding($value, 'UTF-8')) {
                            $issues[] = "字段 {$field} 包含可疑字节序列";
                        }
                        
                        // 检查异常长度
                        if (strlen($value) > 1000) {
                            $issues[] = "字段 {$field} 长度异常: " . strlen($value) . " 字节";
                        }
                    }
                }
                
                if (!empty($issues)) {
                    $problematicRecords[] = [
                        'id' => $record->id ?? 'unknown',
                        'created_at' => $record->created_at ?? 'unknown',
                        'issues' => $issues,
                        'record' => $record
                    ];
                }
                
                if (($index + 1) % 25 == 0) {
                    $this->line("已检查 " . ($index + 1) . " 条记录...");
                }
            }
            
            if (empty($problematicRecords)) {
                $this->info("✅ 未发现明显的 UTF-8 编码问题");
                
                // 额外检查：尝试 JSON 编码所有记录
                $this->line("\n=== JSON 编码测试 ===");
                $jsonIssues = 0;
                
                foreach ($records as $record) {
                    $json = json_encode($record);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $jsonIssues++;
                        if ($jsonIssues <= 5) { // 只显示前5个
                            $this->error("记录 ID {$record->id} JSON 编码失败: " . json_last_error_msg());
                        }
                    }
                }
                
                if ($jsonIssues > 0) {
                    $this->warn("发现 {$jsonIssues} 条记录无法进行 JSON 编码");
                } else {
                    $this->info("✅ 所有记录都可以正常 JSON 编码");
                }
                
                return;
            }
            
            $this->warn("发现 " . count($problematicRecords) . " 条有问题的记录:");
            
            foreach ($problematicRecords as $index => $problem) {
                if ($index >= 10) {
                    $this->line("... 省略剩余 " . (count($problematicRecords) - 10) . " 条记录");
                    break;
                }
                
                $this->line("记录 ID: {$problem['id']} (时间: {$problem['created_at']})");
                foreach ($problem['issues'] as $issue) {
                    $this->line("  - {$issue}");
                }
                $this->line("");
            }
            
            if ($shouldFix) {
                $this->fixProblematicRecords($tableName, $problematicRecords);
            } else {
                $this->info("💡 使用 --fix 参数来自动修复这些问题");
            }
            
        } catch (\Exception $e) {
            $this->error("检查数据失败: " . $e->getMessage());
            $this->line("SQL 错误详情: " . $e->getTraceAsString());
        }
    }

    private function getTextFields($tableName)
    {
        $defaultFields = [
            'v2_subscribe_pull_log' => ['os', 'device', 'country', 'city', 'user_agent'],
            'v2_user' => ['email', 'password', 'token', 'remarks'],
            'v2_system_log' => ['action', 'description', 'data']
        ];

        return $defaultFields[$tableName] ?? ['name', 'description', 'content', 'data'];
    }

    private function fixProblematicRecords($tableName, $problematicRecords)
    {
        $this->line("\n=== 开始修复问题数据 ===");
        
        if (!$this->confirm("确定要修复 " . count($problematicRecords) . " 条记录吗？这将直接修改数据库！")) {
            $this->info("修复已取消");
            return;
        }
        
        $fixedCount = 0;
        $textFields = $this->getTextFields($tableName);
        
        foreach ($problematicRecords as $problem) {
            try {
                $record = $problem['record'];
                $updates = [];
                
                foreach ($textFields as $field) {
                    if (isset($record->$field) && !empty($record->$field)) {
                        $originalValue = $record->$field;
                        $cleanedValue = $this->cleanUtf8String($originalValue);
                        
                        if ($originalValue !== $cleanedValue) {
                            $updates[$field] = $cleanedValue;
                        }
                    }
                }
                
                if (!empty($updates)) {
                    DB::table($tableName)
                        ->where('id', $record->id)
                        ->update($updates);
                    
                    $fixedCount++;
                    $this->info("✅ 修复记录 ID: {$record->id}");
                } else {
                    $this->line("记录 ID: {$record->id} 无需修复");
                }
                
            } catch (\Exception $e) {
                $this->error("修复记录 ID: {$problem['id']} 失败: " . $e->getMessage());
            }
        }
        
        $this->info("修复完成，共修复 {$fixedCount} 条记录");
    }

    private function cleanUtf8String($input)
    {
        if (!is_string($input)) {
            return $input;
        }
        
        // 1. 移除或替换无效的 UTF-8 字符
        $cleaned = mb_convert_encoding($input, 'UTF-8', 'UTF-8');
        
        // 2. 移除控制字符（保留换行符和制表符）
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $cleaned);
        
        // 3. 处理可能的问题字符
        $cleaned = preg_replace('/[\x{FFFD}]/u', '', $cleaned); // 移除替换字符
        
        // 4. 限制长度
        if (strlen($cleaned) > 500) {
            $cleaned = substr($cleaned, 0, 500) . '...';
        }
        
        // 5. 修剪空白字符
        $cleaned = trim($cleaned);
        
        return $cleaned;
    }
}
