<?php // app/Http/Controllers/PaymentController.php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ClassMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use App\Support\ClassAccess;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Jobs\ProcessPaymentProof;
use App\Services\FundNotificationService;

class PaymentController extends Controller
{
    // ====================== MEMBER SUBMIT (có thể kèm ảnh) ======================

    public function submit(Request $r, Classroom $class, Invoice $invoice): JsonResponse
    {
        ClassAccess::ensureMember($r->user(), $class);
        abort_unless($invoice->cycle->class_id === $class->id, 404);

        $member = ClassMember::where('class_id', $class->id)
            ->where('user_id', $r->user()->id)->firstOrFail();

        abort_unless($invoice->member_id === $member->id, 403, 'Không phải hóa đơn của bạn');
        abort_unless(in_array($invoice->status, ['unpaid','submitted'], true), 422, 'Hóa đơn không ở trạng thái cho phép nộp.');

        // quá hạn & allow_late
        $cycle = $invoice->cycle;
        if ($cycle && $cycle->due_date) {
            $due = $cycle->due_date instanceof \Carbon\Carbon
                ? $cycle->due_date->startOfDay()
                : \Illuminate\Support\Carbon::parse($cycle->due_date)->startOfDay();
            if (now()->startOfDay()->gt($due) && !$cycle->allow_late) {
                return response()->json(['message' => 'Kỳ thu đã quá hạn và không cho phép nộp muộn.'], 422);
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

        $pay = Payment::create($data);

        $file = $r->file('image') ?: $r->file('proof');
        if ($file) {
            $path = $file->store('proofs', 'public');
            $pay->proof_path = asset('storage/'.$path);
            $pay->save();

            $abs = storage_path('app/public/'.$path);
            Log::info("Dispatch OCR submit payment #{$pay->id} abs={$abs}");
            ProcessPaymentProof::dispatch($pay->id, $abs)->onQueue('payments');
        }

        if ($invoice->status === 'unpaid') {
            $invoice->update(['status' => 'submitted']);
        }

        // ===== GỬI THÔNG BÁO KHI CÓ PHIẾU NỘP MỚI =====
        try {
            // Service tự lo tìm class_id, người nhận... từ payment
            FundNotificationService::paymentSubmitted($pay);
        } catch (\Throwable $e) {
            Log::warning('paymentSubmitted notification failed: '.$e->getMessage());
        }

        return response()->json(['payment' => $pay], 201);
    }

    // ====================== MEMBER UPLOAD PROOF (multipart) ======================

    public function uploadProof(Request $r, Classroom $class, Payment $payment): JsonResponse
    {
        ClassAccess::ensureMember($r->user(), $class);

        $member = ClassMember::where('class_id', $class->id)
            ->where('user_id', $r->user()->id)->firstOrFail();

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
        $payment->proof_path = asset('storage/'.$path);
        if (!in_array($payment->status, ['submitted','pending'], true)) {
            $payment->status = 'submitted';
        }
        $payment->save();

        $invoice = $payment->invoice()->first();
        if ($invoice && $invoice->status === 'unpaid') {
            $invoice->update(['status' => 'submitted']);
        }

        // GỬI JOB
        $abs = storage_path('app/public/'.$path);
        Log::info("Dispatch OCR upload payment #{$payment->id} abs={$abs}");
        ProcessPaymentProof::dispatch($payment->id, $abs)->onQueue('payments');

        $payment->refresh();

        // ===== THÔNG BÁO KHI TỪ TRẠNG THÁI KHÁC CHUYỂN SANG SUBMITTED =====
        try {
            if (!$wasSubmitted && $payment->status === 'submitted') {
                FundNotificationService::paymentSubmitted($payment);
            }
        } catch (\Throwable $e) {
            Log::warning('paymentSubmitted (uploadProof) notification failed: '.$e->getMessage());
        }

        return response()->json(['payment' => $payment]);
    }

    // ====================== TREASURER/OWNER: LIST ======================

    // GET /classes/{class}/payments?status=submitted|verified|rejected|invalid&group=cycle&ai_failed=1
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
            ->leftJoin('users as invu', 'invu.id', '=', 'p.invalidated_by') // NEW
            ->where('fc.class_id', $class->id)
            ->when($status, fn ($q) => $q->where('p.status', $status))
            ->when($aiFailed, function ($q) {
                $q->where('p.auto_verified', true)
                  ->whereNotNull('p.verify_reason_code'); // => thất bại AI
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
                    'cycle_id'   => (int) $cycleId,
                    'cycle_name' => $first->cycle_name,
                    'payments'   => $items->map(fn ($x) => [
                        'id'                    => (int) $x->id,
                        'invoice_id'            => (int) $x->invoice_id,
                        'amount'                => (int) $x->amount,
                        'status'                => $x->status,
                        'method'                => $x->method,
                        'payer_name'            => $x->payer_name,
                        'payer_email'           => $x->payer_email,
                        'proof_path'            => $x->proof_path,
                        'created_at'            => $x->created_at,
                        // AI
                        'auto_verified'         => (bool) $x->auto_verified,
                        'verify_reason_code'    => $x->verify_reason_code,
                        'verify_reason_detail'  => $x->verify_reason_detail,
                        'ocr_amount'            => $x->ocr_amount ? (int) $x->ocr_amount : null,
                        'ocr_txn_ref'           => $x->ocr_txn_ref,
                        'ocr_method'            => $x->ocr_method,
                        // invalid
                        'invalidated_at'        => $x->invalidated_at,
                        'invalid_reason'        => $x->invalid_reason,
                        'invalid_note'          => $x->invalid_note,
                        'invalidated_by_name'   => $x->invalidated_by_name,
                        'verified_by_name'      => $x->verified_by_name,
                    ])->values(),
                ];
            })->values();

            return response()->json(['cycles' => $grouped]);
        }

        // Non-grouped
        return response()->json(['payments' => $q->get()]);
    }

    // ====================== TREASURER/OWNER: DETAIL ======================

    // GET /classes/{class}/payments/{payment}
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
            ->leftJoin('users as invu', 'invu.id', '=', 'p.invalidated_by') // NEW
            ->where('p.id', $payment->id)
            ->select([
                'p.id','p.invoice_id','p.amount','p.status','p.method','p.txn_ref',
                'p.proof_path','p.created_at','p.verified_at',
                'p.auto_verified','p.verify_reason_code','p.verify_reason_detail',
                'p.ocr_amount','p.ocr_txn_ref','p.ocr_method',
                // invalid meta
                'p.invalidated_at','p.invalid_reason','p.invalid_note',
                'invu.name as invalidated_by_name',

                'u.name as payer_name','u.email as payer_email',
                'i.amount as invoice_amount','i.status as invoice_status',
                'fc.name as cycle_name',
                'v.name as verified_by_name',
            ])
            ->first();

        return response()->json(['payment' => $row]);
    }

    // ====================== TREASURER/OWNER: VERIFY ======================

    // POST /classes/{class}/payments/{payment}/verify  { action: approve|reject }
    public function verify(Request $r, Classroom $class, Payment $payment): JsonResponse
    {
        ClassAccess::ensureTreasurerLike($r->user(), $class);

        $data = $r->validate([
            'action' => 'required|in:approve,reject',
        ]);

        // đảm bảo phiếu thuộc lớp
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

            // đồng bộ invoice -> verified khi đủ số tiền
            $invoice = $payment->invoice()->with('payments')->first();
            $sumVerified = $invoice->payments->where('status', 'verified')->sum('amount');
            if ($sumVerified >= $invoice->amount && $invoice->status !== 'paid') {
                $invoice->update(['status' => 'verified']);
            }
        } else {
            // reject
            $payment->update([
                'status'      => 'rejected',
                'verified_by' => $r->user()->id,
                'verified_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Cập nhật thành công', 'status' => $payment->status]);
    }

    // ====================== LIST "ĐÃ DUYỆT" + TAB "KHÔNG HỢP LỆ" ======================

    // GET /classes/{class}/payments/approved  (?status=invalid để lấy tab Không hợp lệ)
    public function approvedList(Request $r, Classroom $class): JsonResponse
    {
        ClassAccess::ensureMember($r->user(), $class);

        $feeCycleId = $r->query('fee_cycle_id');
        $from       = $r->query('from');
        $to         = $r->query('to');
        $group      = $r->query('group'); // 'cycle' | null
        $forceAll   = $r->boolean('all'); // <= giữ như cũ
        $statusOpt  = $r->query('status'); // <= NEW: 'invalid' để lấy tab không hợp lệ

        $me = ClassMember::where('class_id', $class->id)
            ->where('user_id', $r->user()->id)->firstOrFail();

        $isTreasurerLike = in_array($me->role, ['owner', 'treasurer'], true);

        // ====== FILTER NGƯỜI NỘP ======
        $filterMemberId = null;

        if ($r->filled('member_id')) {
            $filterMemberId = (int) $r->query('member_id');
        } elseif ($r->filled('user_id')) {
            $u = ClassMember::where('class_id', $class->id)
                ->where('user_id', (int) $r->query('user_id'))->first();
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
            ->leftJoin('users as invu', 'invu.id', '=', 'p.invalidated_by') // NEW
            ->where('fc.class_id', $class->id)
            ->whereIn('p.status', $approvedStatuses)
            ->when($feeCycleId, fn($q) => $q->where('i.fee_cycle_id', $feeCycleId))
            ->when($filterMemberId, fn($q) => $q->where('p.payer_id', $filterMemberId))
            ->when($from, fn($q) => $q->whereDate("p.$dateCol", '>=', $from))
            ->when($to,   fn($q) => $q->whereDate("p.$dateCol", '<=', $to))
            ->orderByDesc("p.$dateCol")
            ->select([
                'p.id','p.invoice_id','p.amount','p.status','p.method','p.txn_ref','p.proof_path',
                "p.$dateCol as approved_at",
                // invalid meta
                'p.invalidated_at','p.invalid_reason','p.invalid_note',
                'invu.name as invalidated_by_name',

                'u.name as payer_name','u.email as payer_email',
                'i.amount as invoice_amount','i.status as invoice_status',
                'fc.id as cycle_id','fc.name as cycle_name',
                'v.name as verified_by_name',
            ]);

        if ($group === 'cycle') {
            $rows = $q->get();
            $grouped = $rows->groupBy('cycle_id')->map(function ($items, $cycleId) {
                $first = $items->first();
                return [
                    'cycle_id'   => (int) $cycleId,
                    'cycle_name' => $first->cycle_name,
                    'payments'   => $items->map(function ($x) {
                        return [
                            'id'               => (int) $x->id,
                            'invoice_id'       => (int) $x->invoice_id,
                            'amount'           => (int) $x->amount,
                            'method'           => $x->method,
                            'status'           => $x->status,
                            'txn_ref'          => $x->txn_ref,
                            'proof_path'       => $x->proof_path,
                            'approved_at'      => $x->approved_at,
                            'payer_name'       => $x->payer_name,
                            'payer_email'      => $x->payer_email,
                            'invoice_amount'   => (int) $x->invoice_amount,
                            'invoice_status'   => $x->invoice_status,
                            'verified_by_name' => $x->verified_by_name,
                            // invalid meta
                            'invalidated_at'   => $x->invalidated_at,
                            'invalid_reason'   => $x->invalid_reason,
                            'invalid_note'     => $x->invalid_note,
                            'invalidated_by_name' => $x->invalidated_by_name,
                        ];
                    })->values(),
                ];
            })->values();

            return response()->json(['cycles' => $grouped]);
        }

        return response()->json(['payments' => $q->get()]);
    }

    // ====================== DETAIL (đã duyệt) ======================

    public function showApproved(Request $r, Classroom $class, Payment $payment): JsonResponse
    {
        ClassAccess::ensureMember($r->user(), $class);
        // thuộc đúng lớp?
        abort_unless(optional($payment->invoice)->cycle?->class_id === $class->id, 404);

        $payment->loadMissing(['invoice:id,fee_cycle_id,member_id', 'invoice.cycle:id,name']);
        $member = ClassMember::find($payment->payer_id);

        return response()->json([
            'payment' => [
                'id'          => $payment->id,
                'status'      => $payment->status,
                'amount'      => $payment->amount,
                'method'      => $payment->method,
                'note'        => $payment->note,
                'txn_ref'     => $payment->txn_ref,
                'proof_path'  => $payment->proof_path,
                'approved_at' => $payment->approved_at ?? $payment->verified_at ?? $payment->created_at,
                'invoice_id'  => $payment->invoice_id,
                'cycle_name'  => optional($payment->invoice->cycle)->name,
                'payer_name'  => $member?->user?->name ?? $member?->user?->email,
                // invalid meta (nếu có)
                'invalidated_at' => $payment->invalidated_at,
                'invalid_reason' => $payment->invalid_reason,
                'invalid_note'   => $payment->invalid_note,
            ]
        ]);
    }

    // ====================== NEW: ĐÁNH DẤU KHÔNG HỢP LỆ ======================

    // POST /classes/{class}/payments/{payment}/invalidate  { reason: string, note?: string }
    public function invalidate(Request $r, Classroom $class, Payment $payment): JsonResponse
    {
        ClassAccess::ensureTreasurerLike($r->user(), $class);

        // Payment phải thuộc lớp này
        $invoice = $payment->invoice()->with('cycle')->first();
        abort_unless(optional($invoice?->cycle)->class_id === $class->id, 404, 'Không thuộc lớp này');

        // Chỉ cho đánh dấu khi đã duyệt/đã thu
        abort_unless(in_array($payment->status, ['verified','paid'], true), 422, 'Chỉ áp dụng cho phiếu đã duyệt.');

        $data = $r->validate([
            'reason' => 'required|string|max:120',
            'note'   => 'nullable|string',
        ]);

        DB::transaction(function () use ($payment, $invoice, $r, $data) {
            // 1) đổi trạng thái + lưu meta
            $payment->status          = 'invalid';
            $payment->invalid_reason  = $data['reason'];
            $payment->invalid_note    = $data['note'] ?? null;
            $payment->invalidated_at  = now();
            $payment->invalidated_by  = $r->user()->id;
            $payment->save();

            // 2) cập nhật invoice (trừ lại phần đã cộng trước đó)
            $inv = $payment->invoice()->lockForUpdate()->first();
            $sumVerified = $inv->payments()->where('status','verified')->sum('amount');

            if ($sumVerified >= $inv->amount) {
                // vẫn đủ tiền -> giữ 'verified'
                $inv->status = 'verified';
            } else {
                // thiếu tiền -> trả về submitted/unpaid
                $hasSubmitted = $inv->payments()->where('status','submitted')->exists();
                $inv->status  = $hasSubmitted ? 'submitted' : 'unpaid';
                $inv->paid_at = null;
            }
            $inv->save();
        });

        return response()->json(['message' => 'Đã chuyển sang KHÔNG HỢP LỆ', 'status' => 'invalid']);
    }

    // GET /classes/{class}/payments/invalid?... (giữ nguyên)
    public function invalidList(Request $r, Classroom $class): JsonResponse
    {
        ClassAccess::ensureMember($r->user(), $class);

        $feeCycleId = $r->query('fee_cycle_id');
        $from       = $r->query('from');
        $to         = $r->query('to');
        $group      = $r->query('group');   // 'cycle' | null
        $forceAll   = $r->boolean('all');   // member thường chỉ xem của mình, trừ khi all=1 hoặc là thủ quỹ

        // Xác định người dùng hiện tại trong lớp
        $me = ClassMember::where('class_id', $class->id)
            ->where('user_id', $r->user()->id)->firstOrFail();
        $isTreasurerLike = in_array($me->role, ['owner','treasurer'], true);

        // ----- Filter theo người nộp -----
        $filterMemberId = null;
        if ($r->filled('member_id')) {
            $filterMemberId = (int) $r->query('member_id');
        } elseif ($r->filled('user_id')) {
            $u = ClassMember::where('class_id', $class->id)
                ->where('user_id', (int) $r->query('user_id'))->first();
            $filterMemberId = $u?->id;
        }
        if (!$isTreasurerLike && !$forceAll) {
            if ($filterMemberId === null) $filterMemberId = $me->id;
        }

        // Cột ngày dùng để sort/filter
        $dateCol = Schema::hasColumn('payments', 'invalidated_at') ? 'invalidated_at' : 'created_at';

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
            ->when($to,   fn($q) => $q->whereDate("p.$dateCol", '<=', $to))
            ->orderByDesc("p.$dateCol")
            ->select([
                'p.id','p.invoice_id','p.amount','p.status','p.method','p.txn_ref','p.proof_path',
                "p.$dateCol as invalid_at",
                // meta không hợp lệ
                'p.invalidated_at','p.invalid_reason','p.invalid_note',
                'invu.name as invalidated_by_name',
                // thông tin hiển thị
                'u.name as payer_name','u.email as payer_email',
                'i.amount as invoice_amount','i.status as invoice_status',
                'fc.id as cycle_id','fc.name as cycle_name',
            ]);

        if ($group === 'cycle') {
            $rows = $q->get();
            $grouped = $rows->groupBy('cycle_id')->map(function ($items, $cycleId) {
                $first = $items->first();
                return [
                    'cycle_id'   => (int) $cycleId,
                    'cycle_name' => $first->cycle_name,
                    'payments'   => $items->map(function ($x) {
                        return [
                            'id'                 => (int) $x->id,
                            'invoice_id'         => (int) $x->invoice_id,
                            'amount'             => (int) $x->amount,
                            'method'             => $x->method,
                            'status'             => $x->status,           // luôn 'invalid'
                            'txn_ref'            => $x->txn_ref,
                            'proof_path'         => $x->proof_path,
                            'invalid_at'         => $x->invalid_at,       // thời điểm đánh dấu
                            'payer_name'         => $x->payer_name,
                            'payer_email'        => $x->payer_email,
                            'invoice_amount'     => (int) $x->invoice_amount,
                            'invoice_status'     => $x->invoice_status,
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

        // danh sách phẳng
        return response()->json(['payments' => $q->get()]);
    }

    // ====================== DEPRECATED: XOÁ PHIẾU ĐÃ DUYỆT ======================

    public function destroyApproved(Request $r, Classroom $class, Payment $payment): JsonResponse
    {
        // Không cho xóa nữa để giữ dấu vết sổ quỹ
        return response()->json([
            'ok' => false,
            'message' => 'API xoá phiếu đã bị vô hiệu. Vui lòng dùng /payments/{payment}/invalidate để đánh dấu KHÔNG HỢP LỆ.'
        ], 410);
    }
}
