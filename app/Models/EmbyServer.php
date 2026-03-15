<?php
// ============ 2. Model 模型文件 ============
// app/Models/EmbyServer.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmbyServer extends Model
{
    protected $table = 'v2_emby_servers';
    
    protected $fillable = [
        'name',
        'url',
        'api_key',
        'plan_ids',
        'require_yearly',
        'status',
        'max_users',
        'current_users',
        'remarks',
        'urls',
        'user_agent',
        'client_name'
    ];

    protected $casts = [
        'plan_ids' => 'array',
        'require_yearly' => 'boolean',
        'status' => 'boolean',
        'max_users' => 'integer',
        'current_users' => 'integer'
    ];

    protected $hidden = ['api_key'];

    // 关联emby用户
    public function embyUsers()
    {
        return $this->hasMany(EmbyUser::class, 'emby_server_id');
    }

    // 更新当前用户数
    public function updateCurrentUsers()
    {
        $this->current_users = $this->embyUsers()->where('status', 1)->count();
        $this->save();
        return $this;
    }

    // 检查是否还能添加用户
    public function canAddUser()
    {
        if (!$this->max_users) {
            return true;
        }
        return $this->current_users < $this->max_users;
    }

    // 检查套餐是否允许
    public function isPlanAllowed($planId)
    {
        if (!$this->plan_ids) {
            return true;
        }
        return in_array($planId, $this->plan_ids);
    }

    // 获取解密后的API密钥（仅在需要时使用）
    public function getDecryptedApiKey()
    {
        return decrypt($this->attributes['api_key']);
    }

    // 设置API密钥时自动加密
    public function setApiKeyAttribute($value)
    {
        $this->attributes['api_key'] = encrypt($value);
    }
}