<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundNotification extends Model
{
    protected $table = 'fund_notifications';

    protected $fillable = [
        'class_id',
        'type',     // income | expense | expense_comment | payment_comment ...
        'title',
        'amount',
        'data',     // JSON string (payment_id, expense_id, comment_id...)
        'created_by',
    ];

    protected $casts = [
        'amount' => 'integer',
        'data' => 'array',
    ];

    public function recipients()
    {
        return $this->hasMany(FundNotificationRecipient::class, 'notification_id');
    }
}
