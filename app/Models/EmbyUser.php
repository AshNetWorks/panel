<?php
// ============ 3. EmbyUser Model ============
// app/Models/EmbyUser.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmbyUser extends Model
{
    protected $table = 'v2_emby_users';
    
    protected $fillable = [
        'user_id', 'emby_server_id', 'emby_user_id', 'username', 'password', 'expired_at', 'status'
    ];

    protected $casts = [
        'expired_at' => 'datetime',
        'status' => 'boolean'
    ];

    protected $hidden = ['password'];

    // 关联V2Board用户
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    // 关联Emby服务器
    public function embyServer()
    {
        return $this->belongsTo(EmbyServer::class, 'emby_server_id');
    }

    // 检查是否过期
    public function isExpired()
    {
        return $this->expired_at && $this->expired_at->isPast();
    }

    // 检查是否激活
    public function isActive()
    {
        return $this->status && !$this->isExpired();
    }

    // 自动加密密码
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = bcrypt($value);
    }
}