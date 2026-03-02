<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\Expense;
use App\Services\FundNotificationService;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Support\ClassAccess;
use Symfony\Component\HttpFoundation\JsonResponse;

class ExpenseController extends Controller
{
    /**
     * GET /classes/{class}/expenses?fee_cycle_id=...
     * Member xem danh sách khoản chi.
     */
    public function index(Request $r, Classroom $class): JsonResponse
    {
        ClassAccess::ensureMember($r->user(), $class);

        $feeCycleId = $r->query('fee_cycle_id');
        $userFkCol  = Schema::hasColumn('expenses', 'created_by') ? 'created_by' : 'paid_by';

        $rows = DB::table('expenses as e')
            ->leftJoin('fee_cycles as fc', 'fc.id', '=', 'e.fee_cycle_id')
            ->join('users as u', "u.id", "=", "e.$userFkCol")
            ->where('e.class_id', $class->id)
            ->when($feeCycleId, fn($q) => $q->where('e.fee_cycle_id', $feeCycleId))
            ->orderByDesc('e.created_at')
            ->select([
                'e.id', 'e.class_id', 'e.fee_cycle_id',
                'e.title', 'e.amount', 'e.note', 'e.receipt_path',
                'e.created_at',
                'u.name as created_by_name',
                'fc.name as cycle_name',
            ])
            ->get();

        // Chuẩn hóa thêm receipt_url (full URL) cho FE
        $items = $rows->map(function ($e) {
            $arr = (array) $e;
            $arr['receipt_url'] = !empty($e->receipt_path)
                ? asset('storage/' . ltrim($e->receipt_path, '/'))
                : null;
            return $arr;
        });

        return response()->json(['expenses' => $items]);
    }

    /**
     * POST /classes/{class}/expenses
     * Thủ quỹ tạo khoản chi mới.
     */
    public function store(Request $r, Classroom $class): JsonResponse
    {
        ClassAccess::ensureTreasurerLike($r->user(), $class);

        $data = $r->validate([
            'title'        => 'required|string|max:200',
            'amount'       => 'required|integer|min:0',
            'fee_cycle_id' => 'nullable|integer',
            'note'         => 'nullable|string',
        ]);

        // Nếu có fee_cycle_id thì phải thuộc lớp này
        if (!empty($data['fee_cycle_id'])) {
            $ok = DB::table('fee_cycles')
                ->where('id', $data['fee_cycle_id'])
                ->where('class_id', $class->id)
                ->exists();
            abort_unless($ok, 422, 'Kỳ thu không thuộc lớp');
        }

        $payload = [
            'class_id'     => $class->id,
            'fee_cycle_id' => $data['fee_cycle_id'] ?? null,
            'title'        => $data['title'],
            'amount'       => $data['amount'],
            'note'         => $data['note'] ?? null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ];

        // Ghi nhận người tạo / người chi, tuỳ DB hiện tại
        if (Schema::hasColumn('expenses', 'created_by')) {
            $payload['created_by'] = $r->user()->id;
        }
        if (Schema::hasColumn('expenses', 'paid_by')) {
            $payload['paid_by'] = $r->user()->id;
        }

        // Tạo record
        $id = DB::table('expenses')->insertGetId($payload);

        // Lấy lại bằng model để truyền vào service thông báo
        $expense = Expense::find($id);

        // ===== GỬI THÔNG BÁO KHI TẠO KHOẢN CHI MỚI =====
        try {
            if ($expense) {
                // Định nghĩa hàm này trong FundNotificationService
                // ví dụ: public static function expenseCreated(Expense $expense) { ... }
                FundNotificationService::expenseCreated($expense);
            }
        } catch (\Throwable $e) {
            Log::warning('expenseCreated notification failed: ' . $e->getMessage());
        }

        return $this->showOne($id);
    }

    /**
     * PUT /classes/{class}/expenses/{expense}
     * Cập nhật khoản chi.
     */
    public function update(Request $r, Classroom $class, $expenseId): JsonResponse
    {
        ClassAccess::ensureTreasurerLike($r->user(), $class);

        $data = $r->validate([
            'title'        => 'required|string|max:200',
            'amount'       => 'required|integer|min:0',
            'fee_cycle_id' => 'nullable|integer',
            'note'         => 'nullable|string',
        ]);

        $expense = DB::table('expenses')->where('id', $expenseId)->first();
        abort_unless($expense && (int)$expense->class_id === (int)$class->id, 404, 'Expense không thuộc lớp');

        if (!empty($data['fee_cycle_id'])) {
            $ok = DB::table('fee_cycles')
                ->where('id', $data['fee_cycle_id'])
                ->where('class_id', $class->id)
                ->exists();
            abort_unless($ok, 422, 'Kỳ thu không thuộc lớp');
        }

        DB::table('expenses')
            ->where('id', $expenseId)
            ->update([
                'title'        => $data['title'],
                'amount'       => $data['amount'],
                'fee_cycle_id' => $data['fee_cycle_id'] ?? null,
                'note'         => $data['note'] ?? null,
                'updated_at'   => now(),
            ]);

        return $this->showOne($expenseId);
    }

    /**
     * DELETE /classes/{class}/expenses/{expense}
     * Xoá khoản chi.
     */
    public function destroy(Request $r, Classroom $class, $expenseId): JsonResponse
    {
        ClassAccess::ensureTreasurerLike($r->user(), $class);

        $expense = DB::table('expenses')->where('id', $expenseId)->first();
        abort_unless($expense && (int)$expense->class_id === (int)$class->id, 404, 'Expense không thuộc lớp');

        DB::table('expenses')->where('id', $expenseId)->delete();

        if (!empty($expense->receipt_path)) {
            Storage::disk('public')->delete($expense->receipt_path);
        }

        return response()->json(['deleted' => true]);
    }

    /**
     * POST /classes/{class}/expenses/{expense}/receipt
     * Upload / cập nhật hoá đơn (ảnh) cho khoản chi.
     */
    public function uploadReceipt(Request $r, Classroom $class, $expenseId): JsonResponse
    {
        ClassAccess::ensureTreasurerLike($r->user(), $class);

        $expense = DB::table('expenses')->where('id', $expenseId)->first();
        abort_unless($expense && (int)$expense->class_id === (int)$class->id, 404, 'Expense không thuộc lớp');

        $r->validate([
            'image'   => 'nullable|file|image|max:4096',
            'receipt' => 'nullable|file|image|max:4096',
        ]);

        $file = $r->file('image') ?: $r->file('receipt');
        abort_unless($file, 422, 'Chưa chọn file');

        // Lưu ở disk public -> path tương đối 'receipts/xxx.jpg'
        $path = $file->store('receipts', 'public');

        DB::table('expenses')
            ->where('id', $expenseId)
            ->update([
                'receipt_path' => $path, // lưu path tương đối trong DB
                'updated_at'   => now(),
            ]);

        return $this->showOne($expenseId);
    }

    /**
     * Helper: trả về 1 expense kèm receipt_url chuẩn cho FE.
     */
    private function showOne(int $id): JsonResponse
    {
        $userFkCol = Schema::hasColumn('expenses', 'created_by') ? 'created_by' : 'paid_by';

        $row = DB::table('expenses as e')
            ->leftJoin('fee_cycles as fc', 'fc.id', '=', 'e.fee_cycle_id')
            ->join('users as u', "u.id", "=", "e.$userFkCol")
            ->where('e.id', $id)
            ->select([
                'e.id', 'e.class_id', 'e.fee_cycle_id',
                'e.title', 'e.amount', 'e.note', 'e.receipt_path',
                'e.created_at', 'e.updated_at',
                'u.name as created_by_name',
                'fc.name as cycle_name',
            ])
            ->first();

        if ($row) {
            $row = (array) $row;
            $row['receipt_url'] = !empty($row['receipt_path'])
                ? asset('storage/' . ltrim($row['receipt_path'], '/'))
                : null;
        }

        return response()->json(['expense' => $row], 200);
    }
}
