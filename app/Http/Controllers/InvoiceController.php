<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\Invoice;
use App\Models\ClassMember;
use Illuminate\Http\Request;
use App\Support\ClassAccess;

class InvoiceController extends Controller
{
    // ================== MEMBER: danh sách hóa đơn của chính mình ==================
    // GET /classes/{class}/my-invoices
    public function myInvoices(Request $r, Classroom $class)
    {
        ClassAccess::ensureMember($r->user(), $class);

        $member = ClassMember::where('class_id', $class->id)
            ->where('user_id', $r->user()->id)
            ->firstOrFail();

        $invoices = Invoice::where('member_id', $member->id)
            ->with(['cycle:id,name,term,due_date,status,allow_late'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($invoices);
    }

    // ================== DETAIL: chủ hóa đơn hoặc treasurer/owner ==================
    // GET /classes/{class}/invoices/{invoice}
    public function show(Request $r, Classroom $class, Invoice $invoice)
    {
        $invoice->loadMissing('cycle:id,class_id,name,term,due_date,status,allow_late');
        abort_unless(optional($invoice->cycle)->class_id === $class->id, 404);

        $isMine = ClassMember::where('id', $invoice->member_id)
            ->where('class_id', $class->id)
            ->where('user_id', $r->user()->id)
            ->exists();

        $isTreasurerLike = ClassAccess::isTreasurerLike($r->user(), $class);

        abort_unless($isMine || $isTreasurerLike, 403);

        $sumVerified = $invoice->payments()->where('status', 'verified')->sum('amount');
        $sumSubmitted = $invoice->payments()->where('status', 'submitted')->sum('amount');

        $canSubmit = $isMine && in_array($invoice->status, ['unpaid', 'submitted'], true);

        $allowLate = (bool) optional($invoice->cycle)->allow_late;
        $dueDate = optional($invoice->cycle)->due_date;
        $pastDue = $dueDate ? now()->toDateString() > $dueDate->toDateString() : false;

        if ($canSubmit && $pastDue && !$allowLate) {
            $canSubmit = false;
        }

        $canMarkPaid = $isTreasurerLike;

        return response()->json([
            'id' => $invoice->id,
            'fee_cycle_id' => $invoice->fee_cycle_id,
            'amount' => $invoice->amount,
            'status' => $invoice->status,
            'sum_verified' => $sumVerified,
            'sum_submitted' => $sumSubmitted,
            'can_submit' => $canSubmit,
            'can_mark_paid' => $canMarkPaid,
            'title' => optional($invoice->cycle)->name,
            'fee_cycle' => [
                'id' => optional($invoice->cycle)->id,
                'name' => optional($invoice->cycle)->name,
                'term' => optional($invoice->cycle)->term,
                'due_date' => optional($invoice->cycle)->due_date,
                'status' => optional($invoice->cycle)->status,
                'allow_late' => $allowLate,
            ],
        ]);
    }

    // ================== Treasurer/Owner mark paid ==================
    // POST /classes/{class}/invoices/{invoice}/mark-paid
    public function markPaid(Request $r, Classroom $class, Invoice $invoice)
    {
        ClassAccess::ensureTreasurerLike($r->user(), $class);
        abort_unless($invoice->cycle->class_id === $class->id, 404);

        $invoice->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return response()->json($invoice);
    }

    public function unpaidMembers(Request $r, Classroom $class, \App\Models\FeeCycle $cycle)
    {
        ClassAccess::ensureTreasurerLike($r->user(), $class);
        ClassAccess::assertSameClass($cycle->class_id, $class);

        $rows = \App\Models\Invoice::with([
                'member.user:id,name,email,phone'
            ])
            ->where('fee_cycle_id', $cycle->id)
            ->whereIn('status', ['unpaid', 'submitted'])
            ->orderByRaw("
                CASE
                    WHEN status = 'submitted' THEN 0
                    WHEN status = 'unpaid' THEN 1
                    ELSE 2
                END
            ")
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($inv) {
                $u = optional(optional($inv->member)->user);
                return [
                    'invoice_id' => $inv->id,
                    'member_id' => $inv->member_id,
                    'amount' => (int) $inv->amount,
                    'status' => $inv->status,
                    'user_name' => (string) ($u->name ?? ''),
                    'user_email' => (string) ($u->email ?? ''),
                    'user_phone' => (string) ($u->phone ?? ''),
                    'last_submitted_at' => optional(
                        $inv->payments()->whereIn('status', ['submitted', 'verified'])->latest()->first()
                    )->created_at,
                ];
            });

        return response()->json([
            'cycle' => [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'due_date' => $cycle->due_date,
                'allow_late' => $cycle->allow_late ?? false,
            ],
            'items' => $rows,
            'counts' => [
                'unpaid' => $rows->where('status', 'unpaid')->count(),
                'submitted' => $rows->where('status', 'submitted')->count(),
                'total' => $rows->count(),
            ],
        ]);
    }
}