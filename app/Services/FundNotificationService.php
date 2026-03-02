<?php

namespace App\Services;

use App\Models\ClassMember;
use App\Models\Expense;
use App\Models\FundNotification;
use App\Models\FundNotificationRecipient;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class FundNotificationService
{
    /**
     * Hàm core: tạo 1 bản ghi fund_notifications + nhiều fund_notification_recipients
     *
     * @param int         $classId   Lớp nào
     * @param int[]       $userIds   Danh sách user nhận thông báo
     * @param string      $type      income | expense | ...
     * @param string      $title     Tiêu đề ngắn gọn
     * @param int         $amount    Số tiền liên quan (nếu có)
     * @param array       $data      JSON bổ sung: payment_id, expense_id, event, ...
     * @param int|null    $createdBy user tạo thông báo (thường là thủ quỹ)
     */
    public static function notifyUsers(
        int $classId,
        array $userIds,
        string $type,
        string $title,
        int $amount = 0,
        array $data = [],
        ?int $createdBy = null
    ): ?FundNotification {
        // Lọc trùng + bỏ giá trị null/0
        $userIds = array_values(array_filter(array_unique($userIds), fn($v) => (int)$v > 0));

        if (empty($userIds)) {
            return null;
        }

        return DB::transaction(function () use (
            $classId,
            $userIds,
            $type,
            $title,
            $amount,
            $data,
            $createdBy
        ) {
            $notif = FundNotification::create([
                'class_id'   => $classId,
                'type'       => $type,   // income | expense | ...
                'title'      => $title,
                'amount'     => $amount,
                'data'       => $data ?: null,
                'created_by' => $createdBy,
            ]);

            $now  = now();
            $rows = [];

            foreach ($userIds as $uid) {
                $rows[] = [
                    'notification_id' => $notif->id,
                    'user_id'         => $uid,
                    'is_read'         => false,
                    'read_at'         => null,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }

            FundNotificationRecipient::insert($rows);

            return $notif;
        });
    }

    // -------------------------------------------------------------------------
    //                           HELPER INTERNAL
    // -------------------------------------------------------------------------

    /**
     * Lấy danh sách user_id là owner / treasurer của 1 lớp.
     */
    protected static function getTreasurerUserIdsForClass(int $classId): array
    {
        return ClassMember::where('class_id', $classId)
            ->whereIn('role', ['owner', 'treasurer'])
            ->pluck('user_id')
            ->all();
    }

    /**
     * Lấy thông tin "member nộp tiền" dưới dạng ClassMember (có user).
     */
    protected static function getPayerMember(Payment $payment): ?ClassMember
    {
        return ClassMember::with('user')->find($payment->payer_id);
    }

    // -------------------------------------------------------------------------
    //                        CÁC HÀM PUBLIC DÙNG TRONG CONTROLLER
    // -------------------------------------------------------------------------

    /**
     * Gửi thông báo khi member NỘP PHIẾU (status=submitted).
     * Gọi ở PaymentController::submit và uploadProof (khi chuyển sang submitted).
     *
     * Type: income
     * Event: submitted
     */
    public static function paymentSubmitted(Payment $payment): ?FundNotification
    {
        $invoice = $payment->invoice()->with('cycle')->first();
        if (!$invoice || !$invoice->cycle) {
            return null;
        }

        $classId = (int)$invoice->cycle->class_id;

        // Người nhận: owner + treasurer lớp
        $treasurerUserIds = self::getTreasurerUserIdsForClass($classId);
        if (empty($treasurerUserIds)) {
            return null;
        }

        $payerMember = self::getPayerMember($payment);
        $payerName   = $payerMember?->user?->name ?? $payerMember?->user?->email ?? 'thành viên';

        $title = "Phiếu nộp mới từ {$payerName}";

        $data = [
            'event'            => 'submitted',
            'payment_id'       => $payment->id,
            'invoice_id'       => $invoice->id,
            'payer_member_id'  => $payment->payer_id,
            'amount'           => (int)$payment->amount,
            'status'           => $payment->status,
        ];

        return self::notifyUsers(
            classId:   $classId,
            userIds:   $treasurerUserIds,
            type:      'income',
            title:     $title,
            amount:    (int)$payment->amount,
            data:      $data,
            createdBy: $payerMember?->user_id
        );
    }

    /**
     * Gửi thông báo khi phiếu được DUYỆT (approve -> verified).
     * Gọi ở PaymentController::verify (action=approve).
     *
     * Type: income
     * Event: verified
     * Người nhận: chính student (user nộp).
     */
    public static function paymentVerified(Payment $payment): ?FundNotification
    {
        $invoice = $payment->invoice()->with('cycle')->first();
        if (!$invoice || !$invoice->cycle) {
            return null;
        }

        $classId = (int)$invoice->cycle->class_id;
        $payerMember = self::getPayerMember($payment);
        if (!$payerMember || !$payerMember->user_id) {
            return null;
        }

        $title = 'Phiếu nộp quỹ của bạn đã được duyệt';

        $data = [
            'event'            => 'verified',
            'payment_id'       => $payment->id,
            'invoice_id'       => $invoice->id,
            'payer_member_id'  => $payment->payer_id,
            'amount'           => (int)$payment->amount,
            'status'           => $payment->status,
        ];

        return self::notifyUsers(
            classId:   $classId,
            userIds:   [$payerMember->user_id],
            type:      'income',
            title:     $title,
            amount:    (int)$payment->amount,
            data:      $data,
            createdBy: $payment->verified_by
        );
    }

    /**
     * Gửi thông báo khi phiếu BỊ TỪ CHỐI (approve -> reject).
     * Gọi ở PaymentController::verify (action=reject).
     *
     * Type: income
     * Event: rejected
     * Người nhận: student.
     */
    public static function paymentRejected(Payment $payment): ?FundNotification
    {
        $invoice = $payment->invoice()->with('cycle')->first();
        if (!$invoice || !$invoice->cycle) {
            return null;
        }

        $classId = (int)$invoice->cycle->class_id;
        $payerMember = self::getPayerMember($payment);
        if (!$payerMember || !$payerMember->user_id) {
            return null;
        }

        $title = 'Phiếu nộp quỹ của bạn đã bị từ chối';

        $data = [
            'event'            => 'rejected',
            'payment_id'       => $payment->id,
            'invoice_id'       => $invoice->id,
            'payer_member_id'  => $payment->payer_id,
            'amount'           => (int)$payment->amount,
            'status'           => $payment->status,
        ];

        return self::notifyUsers(
            classId:   $classId,
            userIds:   [$payerMember->user_id],
            type:      'income',
            title:     $title,
            amount:    (int)$payment->amount,
            data:      $data,
            createdBy: $payment->verified_by
        );
    }

    /**
     * Gửi thông báo khi phiếu bị đánh dấu KHÔNG HỢP LỆ.
     * Gọi ở PaymentController::invalidate().
     *
     * Type: income
     * Event: invalidated
     * Người nhận: student (và có thể thêm owner/treasurer nếu muốn).
     */
    public static function paymentInvalidated(Payment $payment): ?FundNotification
    {
        $invoice = $payment->invoice()->with('cycle')->first();
        if (!$invoice || !$invoice->cycle) {
            return null;
        }

        $classId     = (int)$invoice->cycle->class_id;
        $payerMember = self::getPayerMember($payment);
        if (!$payerMember || !$payerMember->user_id) {
            return null;
        }

        $title = 'Phiếu nộp quỹ của bạn bị đánh dấu KHÔNG HỢP LỆ';

        $data = [
            'event'            => 'invalidated',
            'payment_id'       => $payment->id,
            'invoice_id'       => $invoice->id,
            'payer_member_id'  => $payment->payer_id,
            'amount'           => (int)$payment->amount,
            'status'           => $payment->status,
            'invalid_reason'   => $payment->invalid_reason ?? null,
        ];

        // Người nhận chính: student
        $userIds = [$payerMember->user_id];

        return self::notifyUsers(
            classId:   $classId,
            userIds:   $userIds,
            type:      'income',
            title:     $title,
            amount:    (int)$payment->amount,
            data:      $data,
            createdBy: $payment->invalidated_by
        );
    }

    /**
     * Gửi thông báo khi TẠO KHOẢN CHI MỚI.
     * Gọi ở ExpenseController::store().
     *
     * Type: expense
     * Event: created
     * Người nhận: toàn bộ member trong lớp.
     */
    public static function expenseCreated(Expense $expense): ?FundNotification
    {
        $classId = (int)$expense->class_id;
        if ($classId <= 0) {
            return null;
        }

        // Tất cả user trong lớp
        $userIds = ClassMember::where('class_id', $classId)
            ->pluck('user_id')
            ->all();

        if (empty($userIds)) {
            return null;
        }

        $title = 'Khoản chi mới: ' . $expense->title;

        $data = [
            'event'        => 'created',
            'expense_id'   => $expense->id,
            'fee_cycle_id' => $expense->fee_cycle_id,
            'amount'       => (int)$expense->amount,
        ];

        // created_by / paid_by nếu có cột
        $createdBy = null;
        if (property_exists($expense, 'created_by') && $expense->created_by) {
            $createdBy = $expense->created_by;
        } elseif (property_exists($expense, 'paid_by') && $expense->paid_by) {
            $createdBy = $expense->paid_by;
        }

        return self::notifyUsers(
            classId:   $classId,
            userIds:   $userIds,
            type:      'expense',
            title:     $title,
            amount:    (int)$expense->amount,
            data:      $data,
            createdBy: $createdBy
        );
    }
}
