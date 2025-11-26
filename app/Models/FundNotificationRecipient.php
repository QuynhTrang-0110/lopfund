<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundNotificationRecipient extends Model
{
    protected $table = 'fund_notification_recipients';

    protected $fillable = [
        'notification_id',
        'user_id',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function notification()
    {
        return $this->belongsTo(FundNotification::class, 'notification_id');
    }
}
