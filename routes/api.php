<?php
use App\Http\Controllers\Api\FundNotificationController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\FeeCycleController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ExpenseRequestController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ClassController;
use App\Http\Controllers\FundAccountController;
use App\Http\Controllers\ExpenseCommentController;
use App\Http\Controllers\Api\PaymentCommentController;
use App\Http\Controllers\NotificationController;
// Auth cơ bản (Sanctum)
Route::post('/register', [AuthController::class,'register']);
Route::post('/login',    [AuthController::class,'login']);

Route::get('health', fn () => response()->json(['ok' => true, 'ts' => now()]));
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class,'logout']);
    Route::get('/me',      [AuthController::class,'me']);
    Route::put('/me',      [ProfileController::class,'update']);
    Route::put('/me/password', [ProfileController::class,'changePassword']);

    Route::middleware('auth:sanctum')->group(function () {
    Route::get('/classes',  [ClassController::class, 'index']); // liệt kê lớp của tôi
    Route::post('/classes', action: [ClassController::class, 'store']); // chỉ Owner tạo được
    Route::post('/classes/join', [ClassController::class, 'joinByCode']);
    Route::post('/classes/{class}/members/{userId}/role', [ClassController::class, 'setRole']);
    Route::post('/classes/{class}/transfer-ownership/{userId}', [ClassController::class, 'transferOwnership']);
    Route::get('/classes/{class}/members', [ClassController::class, 'members']);
    Route::get('/classes/{class}/my-role', [ClassController::class, 'myRole']);

    });
    //Thông báo 
    Route::middleware('auth:sanctum')->group(function () {

    Route::get('/notifications', [FundNotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [FundNotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/read', [FundNotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [FundNotificationController::class, 'markAllAsRead']);

});

    // Fee Cycles & Invoices
    Route::middleware('auth:sanctum')->group(function () {
    Route::get('/classes/{class}/fee-cycles', [FeeCycleController::class, 'index']);
    Route::post('/classes/{class}/fee-cycles', [FeeCycleController::class,'store']);
    Route::post('/classes/{class}/fee-cycles/{cycle}/generate-invoices', [FeeCycleController::class,'generateInvoices']);
    Route::get('/classes/{class}/fee-cycles/{cycle}/report', [FeeCycleController::class,'report']);
    Route::post('/classes/{class}/fee-cycles/{cycle}/status', [FeeCycleController::class,'updateStatus']);
    Route::get('/classes/{class}/my-invoices', [InvoiceController::class,'myInvoices']);
});

    // Payments (phiếu nộp + minh chứng)
    Route::get('/classes/{class}/fund-account',  [FundAccountController::class, 'show']);   // member được xem
    Route::put('/classes/{class}/fund-account',  [FundAccountController::class, 'upsert']); // owner/treasurer cấu hình
    Route::get('/classes/{class}/fund-account/summary', [FundAccountController::class, 'summary']);
    Route::get('/classes/{class}/ledger', [FundAccountController::class, 'ledger']);// Sổ quỹ (ledger) – ai là member của lớp đều xem được

    Route::post('/classes/{class}/invoices/{invoice}/payments', [PaymentController::class,'submit']);
    Route::post('/classes/{class}/payments/{payment}/proof', [PaymentController::class,'uploadProof']);
    Route::post('/classes/{class}/invoices/{invoice}/mark-paid', [InvoiceController::class,'markPaid']);
    Route::get('/classes/{class}/invoices/{invoice}', [InvoiceController::class,'show']);
    Route::get('/classes/{class}/payments/approved', [PaymentController::class, 'approvedList']);
    Route::get('/classes/{class}/payments', [PaymentController::class,'index']);
    Route::get('/classes/{class}/payments/{payment}', [PaymentController::class,'show']);
    Route::post('/classes/{class}/payments/{payment}/verify', [PaymentController::class,'verify']);
    //phiếu không hợp lệ
    Route::post('/classes/{class}/payments/{payment}/invalidate', [PaymentController::class,'invalidate']);
    Route::get('/classes/{class}/payments/invalid', [PaymentController::class, 'invalidList']);
    Route::get('payments/{payment}/comments', [PaymentCommentController::class, 'index']);
    Route::post('payments/{payment}/comments', [PaymentCommentController::class, 'store']);
    
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/{id}/read', [NotificationController::class, 'markRead']);
    });
});

    // xoá payment đã duyệt
    Route::middleware('auth:sanctum')->group(function () {
    Route::get('/classes/{class}/payments/{payment}', [PaymentController::class, 'showApproved']);
    Route::delete('/classes/{class}/payments/{payment}', [PaymentController::class, 'destroyApproved']);
    });

    // Expense Requests & Expenses
    Route::get('/classes/{class}/expense-requests', [ExpenseRequestController::class,'index']);
    Route::post('/classes/{class}/expense-requests', [ExpenseRequestController::class,'store']);
    Route::post('/classes/{class}/expense-requests/{req}/approve', [ExpenseRequestController::class,'approve']);
    Route::post('/classes/{class}/expense-requests/{req}/reject',  [ExpenseRequestController::class,'reject']);
    // Expense comments (bình luận dưới khoản chi)
    Route::get('/classes/{class}/expenses/{expense}/comments', [ExpenseCommentController::class, 'index']);
    Route::post('/classes/{class}/expenses/{expense}/comments', [ExpenseCommentController::class, 'store']);

    Route::middleware('auth:sanctum')->group(function () {
    // Expenses

    Route::get('/classes/{class}/expenses', [ExpenseController::class, 'index']);
    Route::post('/classes/{class}/expenses', [ExpenseController::class, 'store']);
    Route::put('/classes/{class}/expenses/{expense}', [ExpenseController::class, 'update']);
    Route::delete('/classes/{class}/expenses/{expense}', [ExpenseController::class, 'destroy']);

    // Upload hóa đơn/biên nhận ảnh cho expense
    Route::post('/classes/{class}/expenses/{expense}/receipt', [ExpenseController::class, 'uploadReceipt']);
});
    Route::get('/classes/{class}/fee-cycles/{cycle}/unpaid-members', [InvoiceController::class, 'unpaidMembers']);
    });

Route::get('/__debug/enqueue', function () {
    dispatch(function () { Log::warning('DEBUG queued closure fired'); })
        ->onQueue('payments');

    return response()->json(['enqueued' => true]);
});
