<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Payment;
use App\Models\PaymentComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentCommentController extends Controller
{
    // GET /api/classes/{class}/payments/{payment}/comments
    public function index(Classroom $class, Payment $payment)
    {
        // đảm bảo payment thuộc class đó
        if ($payment->classroom_id !== $class->id) {
            return response()->json(['message' => 'Payment không thuộc lớp này'], 404);
        }

        $comments = PaymentComment::query()
            ->where('payment_id', $payment->id)
            ->with('user:id,name')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($c) {
                return [
                    'id'         => $c->id,
                    'payment_id' => $c->payment_id,
                    'user_id'    => $c->user_id,
                    'user_name'  => optional($c->user)->name,
                    'body'       => $c->body,
                    'created_at' => $c->created_at?->format('Y-m-d H:i:s'),
                    'updated_at' => $c->updated_at?->format('Y-m-d H:i:s'),
                ];
            });

        return response()->json($comments);
    }

    // POST /api/classes/{class}/payments/{payment}/comments
    public function store(Request $request, Classroom $class, Payment $payment)
    {
        if ($payment->classroom_id !== $class->id) {
            return response()->json(['message' => 'Payment không thuộc lớp này'], 404);
        }

        $data = $request->validate([
            'body' => ['required', 'string'],
        ]);

        $user = Auth::user();

        $comment = PaymentComment::create([
            'payment_id' => $payment->id,
            'user_id'    => $user->id,
            'body'       => $data['body'],
        ]);

        $comment->load('user:id,name');

        return response()->json([
            'id'         => $comment->id,
            'payment_id' => $comment->payment_id,
            'user_id'    => $comment->user_id,
            'user_name'  => optional($comment->user)->name,
            'body'       => $comment->body,
            'created_at' => $comment->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $comment->updated_at?->format('Y-m-d H:i:s'),
        ], 201);
    }
}
