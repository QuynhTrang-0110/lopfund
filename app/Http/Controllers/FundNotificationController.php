<?php

namespace App\Http\Controllers;

use App\Models\FundNotificationRecipient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FundNotificationController extends Controller
{
    public function index(Request $request)
    {
        $items = DB::table('fund_notification_recipients as r')
            ->join('fund_notifications as n', 'n.id', '=', 'r.notification_id')
            ->where('r.user_id', $request->user()->id)
            ->orderByDesc('n.created_at')
            ->select([
                'n.id',
                'n.type',
                'n.title',
                'n.amount',
                'n.created_at',
                'r.is_read as read',
                DB::raw('JSON_UNQUOTE(JSON_EXTRACT(n.data, "$.event")) as event'),
                DB::raw('n.data as data'),
            ])
            ->paginate(20);

        return response()->json($items);
    }

    public function unreadCount(Request $request)
    {
        $count = FundNotificationRecipient::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->count();

        return response()->json(['unread' => $count]);
    }

    public function markAsRead(Request $request, int $id)
    {
        FundNotificationRecipient::where('user_id', $request->user()->id)
            ->where('notification_id', $id)
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true]);
    }

    public function markAllAsRead(Request $request)
    {
        FundNotificationRecipient::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true]);
    }
}
