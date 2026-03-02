<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundNotification extends Model
{
    // Bảng trong DB: fund_notifications
    protected $table = 'fund_notifications';

    protected $fillable = [
        'class_id',
        'type',       // income | expense | ...
        'title',
        'amount',
        'data',
        'created_by',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(FundNotificationRecipient::class, 'notification_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'class_id');
    }
}
