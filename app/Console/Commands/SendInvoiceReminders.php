<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\FundNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendInvoiceReminders extends Command
{
    protected $signature = 'invoices:send-reminders';
    protected $description = 'Gửi thông báo nhắc hạn đóng quỹ và quá hạn cho các invoice chưa thanh toán';

    public function handle(): int
    {
        $today = now()->startOfDay();

        $invoices = Invoice::with('cycle')
            ->whereIn('status', ['unpaid', 'partial'])
            ->get();

        $dueSoonCount = 0;
        $overdueCount = 0;

        foreach ($invoices as $invoice) {
            $dueDateRaw = optional($invoice->cycle)->due_date;
            if (!$dueDateRaw) {
                continue;
            }

            $dueDate = Carbon::parse($dueDateRaw)->startOfDay();
            $days = $today->diffInDays($dueDate, false);

            if ($days === 3 || $days === 1) {
                $notif = FundNotificationService::invoiceDueSoon($invoice);
                if ($notif) {
                    $dueSoonCount++;
                }
                continue;
            }

            if ($days < 0) {
                $notif = FundNotificationService::invoiceOverdue($invoice);
                if ($notif) {
                    $overdueCount++;
                }
            }
        }

        $this->info("Invoice reminders sent. due_soon={$dueSoonCount}, overdue={$overdueCount}");

        return self::SUCCESS;
    }
}