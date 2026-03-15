<?php
// 2. 模型文件（保持不变）
// 文件路径: app/Models/SubscribeLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscribeLog extends Model
{
    protected $table = 'v2_subscribe_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp'
    ];
    
    const UPDATED_AT = null;

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}