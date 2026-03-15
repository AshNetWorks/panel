<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCheckin extends Model
{
    protected $table = 'v2_user_checkin';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'checkin_date',
        'traffic_amount',
        'traffic_type',
        'is_bonus',
        'ip_address',
        'user_agent',
        'created_at'
    ];

    protected $casts = [
        'checkin_date' => 'date',
        'created_at' => 'integer'
    ];

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
