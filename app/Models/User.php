<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
    // ============ 5. 扩展User模型 ============
	// 在现有的 app/Models/User.php 中添加以下方法

	/**
	 * 用户的Emby账号关联
	 */
	public function embyUsers()
	{
		return $this->hasMany(\App\Models\EmbyUser::class, 'user_id');
	}

	/**
	 * 检查用户是否可以使用Emby服务
	 */
	public function canUseEmby()
	{
		return $this->plan_id && 
			   $this->expired_at && 
			   $this->expired_at->isFuture() && 
			   $this->status === 1;
	}
}
