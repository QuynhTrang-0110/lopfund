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
    public static function notifyUsers(
        int $classId,
        array $userIds,
        string $type,
        string $title,
        ?int $amount = null,
        array $data = [],
        ?int $createdBy = null,
    ): ?FundNotification {
        $userIds = array_values(array_unique(array_filter(array_map(
            static fn ($v) => (int) $v,
            $userIds
        ), static fn ($v) => $v > 0)));

        if (empty($userIds)) {
            return null;
        }

        return DB::transaction(function () use ($classId, $userIds, $type, $title, $amount, $data, $createdBy) {
            $notif = FundNotification::create([
                'class_id' => $classId,
                'type' => $type,
                'title' => $title,
                'amount' => $amount,
                'data' => empty($data) ? null : $data,
                'created_by' => $createdBy,
            ]);

            $now = now();
            $rows = [];

            foreach ($userIds as $uid) {
                $rows[] = [
                    'notification_id' => $notif->id,
                    'user_id' => $uid,
                    'is_read' => false,
                    'read_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            FundNotificationRecipient::insert($rows);

            return $notif;
        });
    }

    protected static function getTreasurerUserIdsForClass(int $classId): array
    {
        return ClassMember::where('class_id', $classId)
            ->whereIn('role', ['owner', 'treasurer'])
            ->pluck('user_id')
            ->all();
    }

    protected static function getPayerMember(Payment $payment): ?ClassMember
    {
        return ClassMember::with('user')->find($payment->payer_id);
    }

    public static function invoicesGenerated(
        int $classId,
        int $cycleId,
        string $cycleName,
        int $amount,
        ?int $createdBy = null,
    ): ?FundNotification {
        $userIds = ClassMember::where('class_id', $classId)
            ->where('status', 'active')
            ->pluck('user_id')
            ->all();

        if (empty($userIds)) {
            return null;
        }

        return self::notifyUsers(
            classId: $classId,
            userIds: $userIds,
            type: 'invoice',
            title: 'Đã phát hóa đơn kỳ thu: ' . $cycleName,
            amount: $amount,
            data: [
                'event' => 'invoice_generated',
                'fee_cycle_id' => $cycleId,
                'amount' => $amount,
            ],
            createdBy: $createdBy,
        );
    }

    public static function paymentSubmitted(Payment $payment): ?FundNotification
    {
        $invoice = $payment->invoice()->with('cycle')->first();
        if (!$invoice || !$invoice->cycle) {
            return null;
        }

        $classId = (int) $invoice->cycle->class_id;
        $treasurerUserIds = self::getTreasurerUserIdsForClass($classId);
        if (empty($treasurerUserIds)) {
            return null;
        }

        $payerMember = self::getPayerMember($payment);
        $payerName = $payerMember?->user?->name ?? $payerMember?->user?->email ?? 'thành viên';

        return self::notifyUsers(
            classId: $classId,
            userIds: $treasurerUserIds,
            type: 'income',
            title: 'Phiếu nộp mới từ ' . $payerName,
            amount: (int) $payment->amount,
            data: [
                'event' => 'submitted',
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'payer_member_id' => $payment->payer_id,
                'amount' => (int) $payment->amount,
                'status' => $payment->status,
            ],
            createdBy: $payerMember?->user_id,
        );
    }

    public static function paymentVerified(Payment $payment): ?FundNotification
    {
        $invoice = $payment->invoice()->with('cycle')->first();
        if (!$invoice || !$invoice->cycle) {
            return null;
        }

        $classId = (int) $invoice->cycle->class_id;
        $payerMember = self::getPayerMember($payment);
        if (!$payerMember || !$payerMember->user_id) {
            return null;
        }

        return self::notifyUsers(
            classId: $classId,
            userIds: [$payerMember->user_id],
            type: 'income',
            title: 'Phiếu nộp quỹ của bạn đã được duyệt',
            amount: (int) $payment->amount,
            data: [
                'event' => 'verified',
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'payer_member_id' => $payment->payer_id,
                'amount' => (int) $payment->amount,
                'status' => $payment->status,
            ],
            createdBy: $payment->verified_by,
        );
    }

    public static function paymentRejected(Payment $payment): ?FundNotification
    {
        $invoice = $payment->invoice()->with('cycle')->first();
        if (!$invoice || !$invoice->cycle) {
            return null;
        }

        $classId = (int) $invoice->cycle->class_id;
        $payerMember = self::getPayerMember($payment);
        if (!$payerMember || !$payerMember->user_id) {
            return null;
        }

        return self::notifyUsers(
            classId: $classId,
            userIds: [$payerMember->user_id],
            type: 'income',
            title: 'Phiếu nộp quỹ của bạn đã bị từ chối',
            amount: (int) $payment->amount,
            data: [
                'event' => 'rejected',
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'payer_member_id' => $payment->payer_id,
                'amount' => (int) $payment->amount,
                'status' => $payment->status,
            ],
            createdBy: $payment->verified_by,
        );
    }

    public static function paymentInvalidated(Payment $payment): ?FundNotification
    {
        $invoice = $payment->invoice()->with('cycle')->first();
        if (!$invoice || !$invoice->cycle) {
            return null;
        }

        $classId = (int) $invoice->cycle->class_id;
        $payerMember = self::getPayerMember($payment);
        if (!$payerMember || !$payerMember->user_id) {
            return null;
        }

        return self::notifyUsers(
            classId: $classId,
            userIds: [$payerMember->user_id],
            type: 'income',
            title: 'Phiếu nộp quỹ của bạn bị đánh dấu KHÔNG HỢP LỆ',
            amount: (int) $payment->amount,
            data: [
                'event' => 'invalidated',
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'payer_member_id' => $payment->payer_id,
                'amount' => (int) $payment->amount,
                'status' => $payment->status,
                'invalid_reason' => $payment->invalid_reason ?? null,
            ],
            createdBy: $payment->invalidated_by,
        );
    }

    public static function expenseCreated(Expense $expense): ?FundNotification
    {
        $classId = (int) $expense->class_id;
        if ($classId <= 0) {
            return null;
        }

        $userIds = ClassMember::where('class_id', $classId)
            ->where('status', 'active')
            ->pluck('user_id')
            ->all();

        if (empty($userIds)) {
            return null;
        }

        $createdBy = null;
        if (isset($expense->created_by) && $expense->created_by) {
            $createdBy = $expense->created_by;
        } elseif (isset($expense->paid_by) && $expense->paid_by) {
            $createdBy = $expense->paid_by;
        }

        return self::notifyUsers(
            classId: $classId,
            userIds: $userIds,
            type: 'expense',
            title: 'Khoản chi mới: ' . $expense->title,
            amount: (int) $expense->amount,
            data: [
                'event' => 'created',
                'expense_id' => $expense->id,
                'fee_cycle_id' => $expense->fee_cycle_id,
                'amount' => (int) $expense->amount,
            ],
            createdBy: $createdBy,
        );
    }
}
