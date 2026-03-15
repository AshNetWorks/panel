<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscribeWhitelist extends Model
{
    protected $table = 'v2_subscribe_whitelist';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    const UPDATED_AT = null;

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
