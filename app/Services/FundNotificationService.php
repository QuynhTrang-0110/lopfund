<?php

namespace App\Services;

use App\Models\FundNotification;
use App\Models\FundNotificationRecipient;
use App\Models\Payment;
use App\Models\Expense;
use App\Models\User;

class FundNotificationService
{
    public static function notifyUsers(
        int $classId,
        array $userIds,
        string $type,
        string $title,
        int $amount = 0,
        array $data = [],
        ?int $createdBy = null
    ): FundNotification {
        $notif = FundNotification::create([
            'class_id'   => $classId,
            'type'       => $type,
            'title'      => $title,
            'amount'     => $amount,
            'data'       => $data ?: null,
            'created_by' => $createdBy,
        ]);

        $rows = [];
        $now = now();
        foreach (array_unique($userIds) as $uid) {
            $rows[] = [
                'notification_id' => $notif->id,
                'user_id'         => $uid,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        if ($rows) {
            FundNotificationRecipient::insert($rows);
        }

        return $notif;
    }

    /** Khi có payment mới gửi lên (phiếu nộp) */
    public static function paymentSubmitted(Payment $payment): void
    {
        $classId = $payment->classroom_id;
        $amount  = (int) $payment->amount;
        $payer   = optional($payment->payer)->name ?? 'Thành viên';
        $cycle   = optional($payment->cycle)->name ?? '';

        $title = "Có phiếu nộp {$amount}đ từ {$payer}" .
            ($cycle ? " - {$cycle}" : '');

        // owner + treasurer của lớp
        $recipients = User::whereHas('classMembers', function ($q) use ($classId) {
            $q->where('classroom_id', $classId)
              ->whereIn('role', ['owner', 'treasurer']);
        })->pluck('id')->all();

        self::notifyUsers(
            $classId,
            $recipients,
            'income',
            $title,
            $amount,
            [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
            ],
            $payment->user_id
        );
    }

    /** Khi thêm khoản chi */
    public static function expenseCreated(Expense $expense): void
    {
        $classId = $expense->classroom_id;
        $amount  = (int) $expense->amount;
        $creator = optional($expense->creator)->name ?? 'Thủ quỹ';
        $title   = "Thêm khoản chi {$amount}đ - {$expense->title} ({$creator})";

        // gửi cho owner + treasurer
        $recipients = User::whereHas('classMembers', function ($q) use ($classId) {
            $q->where('classroom_id', $classId)
              ->whereIn('role', ['owner', 'treasurer']);
        })->pluck('id')->all();

        self::notifyUsers(
            $classId,
            $recipients,
            'expense',
            $title,
            $amount,
            ['expense_id' => $expense->id],
            $expense->created_by
        );
    }

    /** Khi bình luận vào khoản chi */
    public static function expenseCommentCreated($comment): void
    {
        $expense  = $comment->expense;
        $classId  = $expense->classroom_id;
        $author   = optional($comment->user)->name ?? 'Thành viên';
        $title    = "{$author} bình luận về khoản chi: {$expense->title}";
        $amount   = (int) $expense->amount;

        // người tạo khoản chi + owner + treasurer (trừ chính người comment)
        $recipientIds = collect([
            $expense->created_by,
        ])->merge(
            User::whereHas('classMembers', function ($q) use ($classId) {
                $q->where('classroom_id', $classId)
                  ->whereIn('role', ['owner', 'treasurer']);
            })->pluck('id')
        )->reject(fn ($id) => $id == $comment->user_id)
         ->unique()
         ->values()
         ->all();

        self::notifyUsers(
            $classId,
            $recipientIds,
            'expense_comment',
            $title,
            $amount,
            [
                'expense_id' => $expense->id,
                'comment_id' => $comment->id,
            ],
            $comment->user_id
        );
    }

    /** Khi bình luận vào payment */
    public static function paymentCommentCreated($comment): void
    {
        $payment  = $comment->payment;
        $classId  = $payment->classroom_id;
        $author   = optional($comment->user)->name ?? 'Thành viên';
        $title    = "{$author} bình luận về phiếu nộp #{$payment->id}";
        $amount   = (int) $payment->amount;

        $recipientIds = collect([
            $payment->user_id, // người nộp
        ])->merge(
            User::whereHas('classMembers', function ($q) use ($classId) {
                $q->where('classroom_id', $classId)
                  ->whereIn('role', ['owner', 'treasurer']);
            })->pluck('id')
        )->reject(fn ($id) => $id == $comment->user_id)
         ->unique()
         ->values()
         ->all();

        self::notifyUsers(
            $classId,
            $recipientIds,
            'payment_comment',
            $title,
            $amount,
            [
                'payment_id' => $payment->id,
                'comment_id' => $comment->id,
            ],
            $comment->user_id
        );
    }
}
