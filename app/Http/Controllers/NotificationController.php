<?php

namespace App\Http\Controllers;

use App\Models\FundNotificationRecipient;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $rows = FundNotificationRecipient::query()
            ->where('user_id', $userId)
            ->join('fund_notifications as n', 'n.id', '=', 'fund_notification_recipients.notification_id')
            ->orderByDesc('n.created_at')
            ->limit(50)
            ->get([
                'n.id',
                'n.type',
                'n.title',
                'n.amount',
                'n.created_at',
                'fund_notification_recipients.read_at',
            ]);

        $data = $rows->map(function ($r) {
            return [
                'id'         => (string) $r->id,
                'type'       => $r->type,
                'title'      => $r->title,
                'amount'     => (int) $r->amount,
                'created_at' => $r->created_at?->toIso8601String(),
                'read'       => !is_null($r->read_at),
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    public function markRead(Request $request, int $id)
    {
        $userId = $request->user()->id;

        FundNotificationRecipient::where('user_id', $userId)
            ->where('notification_id', $id)
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request)
    {
        $userId = $request->user()->id;

        FundNotificationRecipient::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
