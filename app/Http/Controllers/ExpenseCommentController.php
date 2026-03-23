<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Services\FundNotificationService;
use App\Support\ClassAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpenseCommentController extends Controller
{
    /**
     * GET /classes/{class}/expenses/{expense}/comments
     * -> Lấy danh sách bình luận của 1 khoản chi
     */
    public function index(Request $request, Classroom $class, int $expenseId): JsonResponse
    {
        ClassAccess::ensureMember($request->user(), $class);

        $expense = DB::table('expenses')->where('id', $expenseId)->first();
        abort_unless(
            $expense && (int) $expense->class_id === (int) $class->id,
            404,
            'Expense không thuộc lớp'
        );

        $rows = DB::table('expense_comments as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.expense_id', $expenseId)
            ->orderBy('c.created_at')
            ->select([
                'c.id',
                'c.expense_id',
                'c.user_id',
                'c.body',
                'c.created_at',
                'c.updated_at',
                'u.name as user_name',
            ])
            ->get();

        return response()->json(['comments' => $rows]);
    }

    /**
     * POST /classes/{class}/expenses/{expense}/comments
     * body: { body: string }
     */
    public function store(Request $request, Classroom $class, int $expenseId): JsonResponse
    {
        ClassAccess::ensureMember($request->user(), $class);

        $expense = DB::table('expenses')->where('id', $expenseId)->first();
        abort_unless(
            $expense && (int) $expense->class_id === (int) $class->id,
            404,
            'Expense không thuộc lớp'
        );

        $data = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $id = DB::table('expense_comments')->insertGetId([
            'expense_id' => $expenseId,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
            'created_at' => now(),
            'updated_at' => null,
        ]);

        $comment = DB::table('expense_comments as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.id', $id)
            ->select([
                'c.id',
                'c.expense_id',
                'c.user_id',
                'c.body',
                'c.created_at',
                'c.updated_at',
                'u.name as user_name',
            ])
            ->first();

        try {
            FundNotificationService::expenseCommented(
                classId: (int) $class->id,
                expenseId: (int) $expenseId,
                expenseTitle: (string) ($expense->title ?? 'Khoản chi'),
                commenterUserId: (int) $request->user()->id,
                commenterName: (string) ($request->user()->name ?? $request->user()->email ?? 'Thành viên'),
                feeCycleId: isset($expense->fee_cycle_id) ? (int) $expense->fee_cycle_id : null,
            );
        } catch (\Throwable $e) {
            Log::warning('expenseCommented notification failed: ' . $e->getMessage());
        }

        return response()->json(['comment' => $comment], 201);
    }
}