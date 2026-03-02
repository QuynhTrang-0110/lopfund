<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FundNotificationRecipient;
use Illuminate\Http\Request;

class FundNotificationController extends Controller
{
    /**
     * GET /api/notifications
     * trả về danh sách thông báo của current user (phân trang).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = FundNotificationRecipient::with('notification')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at');

        $perPage = (int) $request->get('per_page', 20);

        $paginator = $query->paginate($perPage);

        $items = $paginator->getCollection()->map(function (FundNotificationRecipient $rec) {
            $n = $rec->notification;

            return [
                'id'          => $n->id,
                'type'        => $n->type,
                'title'       => $n->title,
                'message'     => $n->message,
                'amount'      => $n->amount,
                'data'        => $n->data,
                'class_id'    => $n->class_id,
                'is_read'     => $rec->is_read,
                'read_at'     => $rec->read_at,
                'created_at'  => $n->created_at,
            ];
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * GET /api/notifications/unread-count
     */
    public function unreadCount(Request $request)
    {
        $user = $request->user();

        $count = FundNotificationRecipient::where('user_id', $user->id)
            ->where('is_read', false)
            ->count();

        return response()->json(['unread' => $count]);
    }

    /**
     * POST /api/notifications/{id}/read
     */
    public function markAsRead(Request $request, int $id)
    {
        $user = $request->user();

        $rec = FundNotificationRecipient::where('user_id', $user->id)
            ->where('notification_id', $id)
            ->firstOrFail();

        if (!$rec->is_read) {
            $rec->is_read = true;
            $rec->read_at = now();
            $rec->save();
        }

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/notifications/read-all
     */
    public function markAllAsRead(Request $request)
    {
        $user = $request->user();

        FundNotificationRecipient::where('user_id', $user->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json(['success' => true]);
    }
}
