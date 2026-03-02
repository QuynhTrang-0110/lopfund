<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPaymentProof;
use App\Models\ClassMember;
use App\Models\Classroom;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\FundNotificationService;
use App\Support\ClassAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Models\Expense;

class PaymentController extends Controller
{
    // =========================================================================
    // ================       MEMBER: SUBMIT PAYMENT       =====================
    // =========================================================================

    /**
     * POST /classes/{class}/invoices/{invoice}/payments/submit
     * Member gửi phiếu nộp (có thể kèm hình).
     */
    public function submit(Request $r, Classroom $class, Invoice $invoice): JsonResponse
    {
        ClassAccess::ensureMember($r->user(), $class);
        abort_unless($invoice->cycle->class_id === $class->id, 404);

        $member = ClassMember::where('class_id', $class->id)
            ->where('user_id', $r->user()->id)
            ->firstOrFail();

        // Invoice phải là của member hiện tại
        abort_unless($invoice->member_id === $member->id, 403, 'Không phải hóa đơn của bạn');
        abort_unless(
            in_array($invoice->status, ['unpaid', 'submitted'], true),
            422,
            'Hóa đơn không ở trạng thái cho phép nộp.'
        );

        // Quá hạn & allow_late
        $cycle = $invoice->cycle;
        if ($cycle && $cycle->due_date) {
            $due = $cycle->due_date instanceof \Carbon\Carbon
                ? $cycle->due_date->startOfDay()
                : \Illuminate\Support\Carbon::parse($cycle->due_date)->startOfDay();

            if (now()->startOfDay()->gt($due) && !$cycle->allow_late) {
                return response()->json([
                    'message' => 'Kỳ thu đã quá hạn và không cho phép nộp muộn.'
                ], 422);
            }
        }

        $data = $r->validate([
            'amount'   => 'required|integer|min:0',
            'method'   => 'sometimes|in:bank,momo,zalopay,cash',
            'txn_ref'  => 'nullable|string|max:100',
            'image'    => 'nullable|image|max:4096',
            'proof'    => 'nullable|image|max:4096',
        ]);

        $data['invoice_id'] = $invoice->id;
        $data['payer_id']   = $member->id;
        $data['status']     = 'submitted';
        $data['method']     = $data['method'] ?? 'bank';

        $payment = Payment::create($data);

        // Lưu file minh chứng (nếu có)
        $file = $r->file('image') ?: $r->file('proof');
        if ($file) {
            $path = $file->store('proofs', 'public');

            $payment->proof_path = asset('storage/' . $path);
            $payment->save();

            $abs = storage_path('app/public/' . $path);
            Log::info("Dispatch OCR submit payment #{$payment->id} abs={$abs}");

            ProcessPaymentProof::dispatch($payment->id, $abs)->onQueue('payments');
        }

        // Cập nhật trạng thái invoice
        if ($invoice->status === 'unpaid') {
            $invoice->update(['status' => 'submitted']);
        }

        // ================== THÔNG BÁO: CÓ PHIẾU NỘP MỚI ==================
        try {
            // Service tự tìm class, người nhận (thủ quỹ / owner...)
            FundNotificationService::paymentSubmitted($payment);
        } catch (\Throwable $e) {
            Log::warning('paymentSubmitted notification failed: ' . $e->getMessage());
        }

        return response()->json(['payment' => $payment], 201);
    }

    // =========================================================================
    // ================       MEMBER: UPLOAD / UPDATE PROOF      ===============
    // =========================================================================

    /**
     * POST /classes/{class}/payments/{payment}/proof
     * Member up lại hình minh chứng (multipart).
     */
    public function uploadProof(Request $r, Classroom $class, Payment $payment): JsonResponse
    {
        ClassAccess::ensureMember($r->user(), $class);

        $member = ClassMember::where('class_id', $class->id)
            ->where('user_id', $r->user()->id)
            ->firstOrFail();

        abort_unless($payment->payer_id === $member->id, 403, 'Không phải phiếu của bạn');

        $r->validate([
            'image' => 'nullable|image|max:4096',
            'proof' => 'nullable|image|max:4096',
        ]);

        $file = $r->file('image') ?: $r->file('proof');
        if (!$file) {
            return response()->json(['message' => 'Chưa chọn file'], 422);
        }

        $wasSubmitted = $payment->status === 'submitted';

        // Lưu file
        $path = $file->store('proofs', 'public');
        $payment->proof_path = asset('storage/' . $path);

        if (!in_array($payment->status, ['submitted', 'pending'], true)) {
            $payment->status = 'submitted';
        }
        $payment->save();

        // Đồng bộ invoice
        $invoice = $payment->invoice()->first();
        if ($invoice && $invoice->status === 'unpaid') {
            $invoice->update(['status' => 'submitted']);
        }

        // Gửi JOB OCR
        $abs = storage_path('app/public/' . $path);
        Log::info("Dispatch OCR upload payment #{$payment->id} abs={$abs}");
        ProcessPaymentProof::dispatch($payment->id, $abs)->onQueue('payments');

        $payment->refresh();

        // ================== THÔNG BÁO: VỪA CHUYỂN SANG SUBMITTED ==================
        try {
            if (!$wasSubmitted && $payment->status === 'submitted') {
                FundNotificationService::paymentSubmitted($payment);
            }
        } catch (\Throwable $e) {
            Log::warning('paymentSubmitted (uploadProof) notification failed: ' . $e->getMessage());
        }

        return response()->json(['payment' => $payment]);
    }

    // =========================================================================
    // ================   TREASURER/OWNER: LIST PENDING/OTHERS   ===============
    // =========================================================================

    /**
     * GET /classes/{class}/payments?status=&group=&ai_failed=
     * Thủ quỹ xem danh sách phiếu theo trạng thái.
     */
    public function index(Request $r, Classroom $class): JsonResponse
    {
        ClassAccess::ensureTreasurerLike($r->user(), $class);

        $status   = $r->query('status', 'submitted');
        $group    = $r->query('group'); // 'cycle' | null
        $aiFailed = $r->boolean('ai_failed');

        $q = DB::table('payments as p')
            ->join('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->join('fee_cycles as fc', 'fc.id', '=', 'i.fee_cycle_id')
            ->join('class_members as cm', 'cm.id', '=', 'p.payer_id')
            ->join('users as u', 'u.id', '=', 'cm.user_id')
            ->leftJoin('users as v', 'v.id', '=', 'p.verified_by')
            ->leftJoin('users as invu', 'invu.id', '=', 'p.invalidated_by')
            ->where('fc.class_id', $class->id)
            ->when($status, fn($q) => $q->where('p.status', $status))
            ->when($aiFailed, function ($q) {
                $q->where('p.auto_verified', true)
                    ->whereNotNull('p.verify_reason_code'); // thất bại AI
            })
            ->orderByDesc('p.created_at')
            ->select([
                'p.id',
                'p.invoice_id',
                'p.amount',
                'p.status',
                'p.method',
                'p.txn_ref',
                'p.proof_path',
                'p.created_at',
                // AI/OCR
                'p.auto_verified',
                'p.verify_reason_code',
                'p.verify_reason_detail',
                'p.ocr_amount',
                'p.ocr_txn_ref',
                'p.ocr_method',
                // invalid meta
                'p.invalidated_at',
                'p.invalid_reason',
                'p.invalid_note',
                'invu.name as invalidated_by_name',

                'u.name as payer_name',
                'u.email as payer_email',
                'i.amount as invoice_amount',
                'fc.id as cycle_id',
                'fc.name as cycle_name',
                'v.name as verified_by_name',
            ]);

        if ($group === 'cycle') {
            $rows = $q->get();

            $grouped = $rows->groupBy('cycle_id')->map(function ($items, $cycleId) {
                $first = $items->first();

                return [
                    'cycle_id'   => (int)$cycleId,
                    'cycle_name' => $first->cycle_name,
                    'payments'   => $items->map(fn($x) => [
                        'id'                   => (int)$x->id,
                        'invoice_id'           => (int)$x->invoice_id,
                        'amount'               => (int)$x->amount,
                        'status'               => $x->status,
                        'method'               => $x->method,
                        'payer_name'           => $x->payer_name,
                        'payer_email'          => $x->payer_email,
                        'proof_path'           => $x->proof_path,
                        'created_at'           => $x->created_at,
                        'auto_verified'        => (bool)$x->auto_verified,
                        'verify_reason_code'   => $x->verify_reason_code,
                        'verify_reason_detail' => $x->verify_reason_detail,
                        'ocr_amount'           => $x->ocr_amount ? (int)$x->ocr_amount : null,
                        'ocr_txn_ref'          => $x->ocr_txn_ref,
                        'ocr_method'           => $x->ocr_method,
                        'invalidated_at'       => $x->invalidated_at,
                        'invalid_reason'       => $x->invalid_reason,
                        'invalid_note'         => $x->invalid_note,
                        'invalidated_by_name'  => $x->invalidated_by_name,
                        'verified_by_name'     => $x->verified_by_name,
                    ])->values(),
                ];
            })->values();

            return response()->json(['cycles' => $grouped]);
        }

        return response()->json(['payments' => $q->get()]);
    }

    // =========================================================================
    // =================== TREASURER/OWNER: DETAIL PENDING =====================
    // =========================================================================

    /**
     * GET /classes/{class}/payments/{payment}
     * Chi tiết phiếu (treasurer).
     */
    public function show(Request $r, Classroom $class, Payment $payment): JsonResponse
    {
        ClassAccess::ensureTreasurerLike($r->user(), $class);

        $ok = DB::table('payments as p')
            ->join('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->join('fee_cycles as fc', 'fc.id', '=', 'i.fee_cycle_id')
            ->where('p.id', $payment->id)
            ->where('fc.class_id', $class->id)
            ->exists();

        if (!$ok) {
            return response()->json(['message' => 'Payment không thuộc lớp này'], 404);
        }

        $row = DB::table('payments as p')
            ->join('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->join('fee_cycles as fc', 'fc.id', '=', 'i.fee_cycle_id')
            ->join('class_members as cm', 'cm.id', '=', 'p.payer_id')
            ->join('users as u', 'u.id', '=', 'cm.user_id')
            ->leftJoin('users as v', 'v.id', '=', 'p.verified_by')
            ->leftJoin('users as invu', 'invu.id', '=', 'p.invalidated_by')
            ->where('p.id', $payment->id)
            ->select([
                'p.id',
                'p.invoice_id',
                'p.amount',
                'p.status',
                'p.method',
                'p.txn_ref',
                'p.proof_path',
                'p.created_at',
                'p.verified_at',
                'p.auto_verified',
                'p.verify_reason_code',
                'p.verify_reason_detail',
                'p.ocr_amount',
                'p.ocr_txn_ref',
                'p.ocr_method',
                // invalid meta
                'p.invalidated_at',
                'p.invalid_reason',
                'p.invalid_note',
                'invu.name as invalidated_by_name',

                'u.name as payer_name',
                'u.email as payer_email',
                'i.amount as invoice_amount',
                'i.status as invoice_status',
                'fc.name as cycle_name',
                'v.name as verified_by_name',
            ])
            ->first();

        return response()->json(['payment' => $row]);
    }

    // =========================================================================
    // =================== TREASURER/OWNER: VERIFY PAYMENT =====================
    // =========================================================================

    /**
     * POST /classes/{class}/payments/{payment}/verify
     * body: { action: approve|reject }
     */
    public function verify(Request $r, Classroom $class, Payment $payment): JsonResponse
    {
        ClassAccess::ensureTreasurerLike($r->user(), $class);

        $data = $r->validate([
            'action' => 'required|in:approve,reject',
        ]);

        // đảm bảo payment thuộc lớp
        $ok = DB::table('payments as p')
            ->join('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->join('fee_cycles as fc', 'fc.id', '=', 'i.fee_cycle_id')
            ->where('p.id', $payment->id)
            ->where('fc.class_id', $class->id)
            ->exists();

        if (!$ok) {
            return response()->json(['message' => 'Payment không thuộc lớp này'], 404);
        }

        if ($payment->status !== 'submitted') {
            return response()->json(['message' => 'Payment không ở trạng thái chờ duyệt'], 422);
        }

        if ($data['action'] === 'approve') {
            $payment->update([
                'status'      => 'verified',
                'verified_by' => $r->user()->id,
                'verified_at' => now(),
            ]);

            // Cập nhật invoice (khi đủ tiền)
            $invoice     = $payment->invoice()->with('payments')->first();
            $sumVerified = $invoice->payments->where('status', 'verified')->sum('amount');

            if ($sumVerified >= $invoice->amount && $invoice->status !== 'paid') {
                // tuỳ bạn giữ 'verified' hay đổi 'paid'
                $invoice->update(['status' => 'verified']);
            }

            // ================== THÔNG BÁO: PHIẾU ĐÃ ĐƯỢC DUYỆT ==================
            try {
                FundNotificationService::paymentVerified($payment);
            } catch (\Throwable $e) {
                Log::warning('paymentVerified notification failed: ' . $e->getMessage());
            }
        } else {
            // reject
            $payment->update([
                'status'      => 'rejected',
                'verified_by' => $r->user()->id,
                'verified_at' => now(),
            ]);

            // ================== THÔNG BÁO: PHIẾU BỊ TỪ CHỐI ==================
            try {
                FundNotificationService::paymentRejected($payment);
            } catch (\Throwable $e) {
                Log::warning('paymentRejected notification failed: ' . $e->getMessage());
            }
        }

        return response()->json([
            'message' => 'Cập nhật thành công',
            'status'  => $payment->status,
        ]);
    }

    // =========================================================================
    // ==================== LIST "ĐÃ DUYỆT" + TAB "KHÔNG HỢP LỆ" ===============
    // =========================================================================

    /**
     * GET /classes/{class}/payments/approved  (?status=invalid để lấy tab Không hợp lệ)
     */
    public function approvedList(Request $r, Classroom $class): JsonResponse
    {
        ClassAccess::ensureMember($r->user(), $class);

        $feeCycleId = $r->query('fee_cycle_id');
        $from       = $r->query('from');
        $to         = $r->query('to');
        $group      = $r->query('group'); // 'cycle' | null
        $forceAll   = $r->boolean('all'); // member thường xem của mình, trừ khi all=1
        $statusOpt  = $r->query('status'); // 'invalid' => tab không hợp lệ

        $me = ClassMember::where('class_id', $class->id)
            ->where('user_id', $r->user()->id)
            ->firstOrFail();

        $isTreasurerLike = in_array($me->role, ['owner', 'treasurer'], true);

        // ====== FILTER NGƯỜI NỘP ======
        $filterMemberId = null;

        if ($r->filled('member_id')) {
            $filterMemberId = (int)$r->query('member_id');
        } elseif ($r->filled('user_id')) {
            $u = ClassMember::where('class_id', $class->id)
                ->where('user_id', (int)$r->query('user_id'))
                ->first();
            $filterMemberId = $u?->id;
        }

        if (!$isTreasurerLike && !$forceAll) {
            if ($filterMemberId === null) {
                $filterMemberId = $me->id;
            }
        }

        // Nếu status=invalid => chỉ lấy không hợp lệ, ngược lại: verified|paid
        $approvedStatuses = $statusOpt === 'invalid'
            ? ['invalid']
            : ['verified', 'paid'];

        $dateCol = Schema::hasColumn('payments', 'approved_at') ? 'approved_at' : 'created_at';

        $q = DB::table('payments as p')
            ->join('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->join('fee_cycles as fc', 'fc.id', '=', 'i.fee_cycle_id')
            ->join('class_members as cm', 'cm.id', '=', 'p.payer_id')
            ->join('users as u', 'u.id', '=', 'cm.user_id')
            ->leftJoin('users as v', 'v.id', '=', 'p.verified_by')
            ->leftJoin('users as invu', 'invu.id', '=', 'p.invalidated_by')
            ->where('fc.class_id', $class->id)
            ->whereIn('p.status', $approvedStatuses)
            ->when($feeCycleId, fn($q) => $q->where('i.fee_cycle_id', $feeCycleId))
            ->when($filterMemberId, fn($q) => $q->where('p.payer_id', $filterMemberId))
            ->when($from, fn($q) => $q->whereDate("p.$dateCol", '>=', $from))
            ->when($to, fn($q) => $q->whereDate("p.$dateCol", '<=', $to))
            ->orderByDesc("p.$dateCol")
            ->select([
                'p.id',
                'p.invoice_id',
                'p.amount',
                'p.status',
                'p.method',
                'p.txn_ref',
                'p.proof_path',
                "p.$dateCol as approved_at",
                // invalid meta
                'p.invalidated_at',
                'p.invalid_reason',
                'p.invalid_note',
                'invu.name as invalidated_by_name',

                'u.name as payer_name',
                'u.email as payer_email',
                'i.amount as invoice_amount',
                'i.status as invoice_status',
                'fc.id as cycle_id',
                'fc.name as cycle_name',
                'v.name as verified_by_name',
            ]);

        if ($group === 'cycle') {
            $rows = $q->get();

            $grouped = $rows->groupBy('cycle_id')->map(function ($items, $cycleId) {
                $first = $items->first();

                return [
                    'cycle_id'   => (int)$cycleId,
                    'cycle_name' => $first->cycle_name,
                    'payments'   => $items->map(function ($x) {
                        return [
                            'id'                 => (int)$x->id,
                            'invoice_id'         => (int)$x->invoice_id,
                            'amount'             => (int)$x->amount,
                            'method'             => $x->method,
                            'status'             => $x->status,
                            'txn_ref'            => $x->txn_ref,
                            'proof_path'         => $x->proof_path,
                            'approved_at'        => $x->approved_at,
                            'payer_name'         => $x->payer_name,
                            'payer_email'        => $x->payer_email,
                            'invoice_amount'     => (int)$x->invoice_amount,
                            'invoice_status'     => $x->invoice_status,
                            'verified_by_name'   => $x->verified_by_name,
                            'invalidated_at'     => $x->invalidated_at,
                            'invalid_reason'     => $x->invalid_reason,
                            'invalid_note'       => $x->invalid_note,
                            'invalidated_by_name'=> $x->invalidated_by_name,
                        ];
                    })->values(),
                ];
            })->values();

            return response()->json(['cycles' => $grouped]);
        }

        return response()->json(['payments' => $q->get()]);
    }

    // =========================================================================
    // =================== DETAIL "ĐÃ DUYỆT" (MEMBER VIEW) =====================
    // =========================================================================

    /**
     * GET /classes/{class}/payments/{payment}/approved
     * Member xem chi tiết phiếu đã duyệt / không hợp lệ.
     */
    public function showApproved(Request $r, Classroom $class, Payment $payment): JsonResponse
    {
        ClassAccess::ensureMember($r->user(), $class);

        // Payment phải thuộc lớp
        abort_unless(optional($payment->invoice)->cycle?->class_id === $class->id, 404);

        $payment->loadMissing([
            'invoice:id,fee_cycle_id,member_id',
            'invoice.cycle:id,name',
        ]);

        $member = ClassMember::find($payment->payer_id);

        return response()->json([
            'payment' => [
                'id'             => $payment->id,
                'status'         => $payment->status,
                'amount'         => $payment->amount,
                'method'         => $payment->method,
                'note'           => $payment->note,
                'txn_ref'        => $payment->txn_ref,
                'proof_path'     => $payment->proof_path,
                'approved_at'    => $payment->approved_at ?? $payment->verified_at ?? $payment->created_at,
                'invoice_id'     => $payment->invoice_id,
                'cycle_name'     => optional($payment->invoice->cycle)->name,
                'payer_name'     => $member?->user?->name ?? $member?->user?->email,
                'invalidated_at' => $payment->invalidated_at,
                'invalid_reason' => $payment->invalid_reason,
                'invalid_note'   => $payment->invalid_note,
            ]
        ]);
    }

    // =========================================================================
    // =================== TREASURER: ĐÁNH DẤU KHÔNG HỢP LỆ ====================
    // =========================================================================

    /**
     * POST /classes/{class}/payments/{payment}/invalidate
     * body: { reason: string, note?: string }
     */
    public function invalidate(Request $r, Classroom $class, Payment $payment): JsonResponse
    {
        ClassAccess::ensureTreasurerLike($r->user(), $class);

        // Payment phải thuộc lớp
        $invoice = $payment->invoice()->with('cycle')->first();
        abort_unless(optional($invoice?->cycle)->class_id === $class->id, 404, 'Không thuộc lớp này');

        // Chỉ cho đánh dấu khi đã duyệt/đã thu
        abort_unless(
            in_array($payment->status, ['verified', 'paid'], true),
            422,
            'Chỉ áp dụng cho phiếu đã duyệt.'
        );

        $data = $r->validate([
            'reason' => 'required|string|max:120',
            'note'   => 'nullable|string',
        ]);

        DB::transaction(function () use ($payment, $r, $data) {
            // 1) đổi trạng thái + lưu meta
            $payment->status         = 'invalid';
            $payment->invalid_reason = $data['reason'];
            $payment->invalid_note   = $data['note'] ?? null;
            $payment->invalidated_at = now();
            $payment->invalidated_by = $r->user()->id;
            $payment->save();

            // 2) cập nhật invoice (trừ lại phần đã cộng trước đó)
            $inv = $payment->invoice()->lockForUpdate()->with('payments')->first();

            $sumVerified = $inv->payments()->where('status', 'verified')->sum('amount');

            if ($sumVerified >= $inv->amount) {
                // vẫn đủ tiền -> giữ 'verified'
                $inv->status = 'verified';
            } else {
                // thiếu tiền -> trả về submitted/unpaid
                $hasSubmitted = $inv->payments()->where('status', 'submitted')->exists();
                $inv->status  = $hasSubmitted ? 'submitted' : 'unpaid';
                $inv->paid_at = null;
            }

            $inv->save();
        });

        // ================== THÔNG BÁO: PHIẾU BỊ ĐÁNH DẤU KHÔNG HỢP LỆ ==================
        try {
            $payment->refresh();
            FundNotificationService::paymentInvalidated($payment);
        } catch (\Throwable $e) {
            Log::warning('paymentInvalidated notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Đã chuyển sang KHÔNG HỢP LỆ',
            'status'  => 'invalid',
        ]);
    }

    // =========================================================================
    // =================== LIST KHÔNG HỢP LỆ (MEMBER / TREASURER) ==============
    // =========================================================================

    /**
     * GET /classes/{class}/payments/invalid?...
     */
    public function invalidList(Request $r, Classroom $class): JsonResponse
    {
        ClassAccess::ensureMember($r->user(), $class);

        $feeCycleId = $r->query('fee_cycle_id');
        $from       = $r->query('from');
        $to         = $r->query('to');
        $group      = $r->query('group');   // 'cycle' | null
        $forceAll   = $r->boolean('all');   // member thường chỉ xem của mình

        $me = ClassMember::where('class_id', $class->id)
            ->where('user_id', $r->user()->id)
            ->firstOrFail();

        $isTreasurerLike = in_array($me->role, ['owner', 'treasurer'], true);

        // Filter theo người nộp
        $filterMemberId = null;
        if ($r->filled('member_id')) {
            $filterMemberId = (int)$r->query('member_id');
        } elseif ($r->filled('user_id')) {
            $u = ClassMember::where('class_id', $class->id)
                ->where('user_id', (int)$r->query('user_id'))
                ->first();
            $filterMemberId = $u?->id;
        }

        if (!$isTreasurerLike && !$forceAll) {
            if ($filterMemberId === null) {
                $filterMemberId = $me->id;
            }
        }

        $dateCol = Schema::hasColumn('payments', 'invalidated_at')
            ? 'invalidated_at'
            : 'created_at';

        $q = DB::table('payments as p')
            ->join('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->join('fee_cycles as fc', 'fc.id', '=', 'i.fee_cycle_id')
            ->join('class_members as cm', 'cm.id', '=', 'p.payer_id')
            ->join('users as u', 'u.id', '=', 'cm.user_id')
            ->leftJoin('users as invu', 'invu.id', '=', 'p.invalidated_by')
            ->where('fc.class_id', $class->id)
            ->where('p.status', 'invalid')
            ->when($feeCycleId, fn($q) => $q->where('i.fee_cycle_id', $feeCycleId))
            ->when($filterMemberId, fn($q) => $q->where('p.payer_id', $filterMemberId))
            ->when($from, fn($q) => $q->whereDate("p.$dateCol", '>=', $from))
            ->when($to, fn($q) => $q->whereDate("p.$dateCol", '<=', $to))
            ->orderByDesc("p.$dateCol")
            ->select([
                'p.id',
                'p.invoice_id',
                'p.amount',
                'p.status',
                'p.method',
                'p.txn_ref',
                'p.proof_path',
                "p.$dateCol as invalid_at",
                'p.invalidated_at',
                'p.invalid_reason',
                'p.invalid_note',
                'invu.name as invalidated_by_name',

                'u.name as payer_name',
                'u.email as payer_email',
                'i.amount as invoice_amount',
                'i.status as invoice_status',
                'fc.id as cycle_id',
                'fc.name as cycle_name',
            ]);

        if ($group === 'cycle') {
            $rows = $q->get();

            $grouped = $rows->groupBy('cycle_id')->map(function ($items, $cycleId) {
                $first = $items->first();

                return [
                    'cycle_id'   => (int)$cycleId,
                    'cycle_name' => $first->cycle_name,
                    'payments'   => $items->map(function ($x) {
                        return [
                            'id'                  => (int)$x->id,
                            'invoice_id'          => (int)$x->invoice_id,
                            'amount'              => (int)$x->amount,
                            'method'              => $x->method,
                            'status'              => $x->status,
                            'txn_ref'             => $x->txn_ref,
                            'proof_path'          => $x->proof_path,
                            'invalid_at'          => $x->invalid_at,
                            'payer_name'          => $x->payer_name,
                            'payer_email'         => $x->payer_email,
                            'invoice_amount'      => (int)$x->invoice_amount,
                            'invoice_status'      => $x->invoice_status,
                            'invalidated_at'      => $x->invalidated_at,
                            'invalid_reason'      => $x->invalid_reason,
                            'invalid_note'        => $x->invalid_note,
                            'invalidated_by_name' => $x->invalidated_by_name,
                        ];
                    })->values(),
                ];
            })->values();

            return response()->json(['cycles' => $grouped]);
        }

        return response()->json(['payments' => $q->get()]);
    }

    // =========================================================================
    // ================== DEPRECATED: XOÁ PHIẾU ĐÃ DUYỆT =======================
    // =========================================================================

    /**
     * Xoá phiếu đã duyệt: ĐÃ VÔ HIỆU HOÁ — dùng invalidate() thay thế.
     */
    public function destroyApproved(Request $r, Classroom $class, Payment $payment): JsonResponse
    {
        return response()->json([
            'ok'      => false,
            'message' => 'API xoá phiếu đã bị vô hiệu. Vui lòng dùng /payments/{payment}/invalidate để đánh dấu KHÔNG HỢP LỆ.',
        ], 410);
    }
}
