<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCheckinStats extends Model
{
    protected $table = 'v2_user_checkin_stats';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'last_checkin_date',
        'last_checkin_at',
        'checkin_streak',
        'total_checkin_days',
        'total_checkin_traffic',
        'updated_at'
    ];

    protected $casts = [
        'last_checkin_date' => 'date',
        'last_checkin_at' => 'integer',
        'updated_at' => 'integer'
    ];

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
