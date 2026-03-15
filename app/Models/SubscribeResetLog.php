<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscribeResetLog extends Model
{
    protected $table = 'v2_subscribe_reset_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp'
    ];

    const UPDATED_AT = null;

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
