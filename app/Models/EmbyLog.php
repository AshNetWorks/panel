<?php
// ============ 4. EmbyLog Model ============
// app/Models/EmbyLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmbyLog extends Model
{
    protected $table = 'v2_emby_logs';
    
    protected $fillable = [
        'user_id', 'emby_server_id', 'action', 'message', 'ip'
    ];

    public $timestamps = false;
    protected $dates = ['created_at'];

    // 关联用户
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    // 关联Emby服务器
    public function embyServer()
    {
        return $this->belongsTo(EmbyServer::class, 'emby_server_id');
    }

    // 创建日志
    public static function createLog($userId, $embyServerId, $action, $message = null, $ip = null)
    {
        return self::create([
            'user_id' => $userId,
            'emby_server_id' => $embyServerId,
            'action' => $action,
            'message' => $message,
            'ip' => $ip ?: request()->ip(),
            'created_at' => now()
        ]);
    }
}
