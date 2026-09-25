<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CommissionInvoice;
use App\Models\CommissionInvoiceExpense;
use App\Models\ChartOfAccounts;
use Carbon\Carbon;

class CommissionReportController extends Controller
{
    public function commissionReports(Request $request)
    {
        $tab  = $request->get('tab', 'CR');
        $from = $request->get('from_date', Carbon::now()->startOfMonth()->toDateString());
        $to   = $request->get('to_date', Carbon::now()->toDateString());
        $status = $request->get('status', '');
        $vendorId = $request->get('vendor_id');
        $customerId = $request->get('customer_id');

        $vendors   = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $customers = ChartOfAccounts::where('account_type', 'customer')->orderBy('name')->get();

        $commissionRegister = collect();
        $vendorWise          = collect();
        $customerWise        = collect();
        $statusOverview      = collect();
        $outstanding         = collect();
        $expenseReport       = collect();

        /* ================= COMMISSION REGISTER =================
         * One row per invoice — the full picture: purchase/sale amounts,
         * both commission legs, expenses, and the two bottom-line figures
         * (Vendor Payable, Customer Receivable) using the same formulas
         * the invoice itself uses (totalVendorPayable/totalCustomerReceivable).
         */
        if ($tab === 'CR') {
            $query = CommissionInvoice::with(['vendor', 'customer'])
                ->whereBetween('invoice_date', [$from, $to]);

            if ($vendorId)   $query->where('vendor_id', $vendorId);
            if ($customerId) $query->where('customer_id', $customerId);
            if ($status)     $query->where('status', $status);

            $commissionRegister = $query->get()->map(function ($ci) {
                return (object)[
                    'id'                     => $ci->id,
                    'date'                   => $ci->invoice_date,
                    'invoice_no'             => $ci->invoice_no,
                    'vendor_name'            => $ci->vendor->name ?? '',
                    'customer_name'          => $ci->customer->name ?? '',
                    'status'                 => $ci->status,
                    'payment_terms'          => $ci->payment_terms,
                    'due_date'               => $ci->dueDate(),
                    'total_purchase_amount'  => (float) $ci->total_purchase_amount,
                    'total_sale_amount'      => (float) $ci->total_sale_amount,
                    'total_gross_weight'     => (float) $ci->total_gross_weight,
                    'total_weight'           => (float) $ci->total_weight,
                    'vendor_commission'      => (float) $ci->total_vendor_commission_amount,
                    'customer_commission'    => (float) $ci->total_customer_commission_amount,
                    'total_commission'       => (float) $ci->total_commission_amount,
                    'total_other_expenses'   => (float) $ci->total_other_expenses,
                    'vendor_payable'         => $ci->totalVendorPayable(),
                    'customer_receivable'    => $ci->totalCustomerReceivable(),
                ];
            });
        }

        /* ================= VENDOR WISE =================
         * Grouped by vendor — total purchase volume, vendor commission
         * earned from them, and net payable across the date range.
         */
        if ($tab === 'VW') {
            $query = CommissionInvoice::with('vendor')
                ->whereBetween('invoice_date', [$from, $to]);

            if ($status) $query->where('status', $status);

            $vendorWise = $query->get()
                ->groupBy('vendor_id')
                ->map(function ($invoices) {
                    $vendorName = $invoices->first()->vendor->name ?? 'Unknown Vendor';

                    return (object)[
                        'vendor_name'           => $vendorName,
                        'count'                 => $invoices->count(),
                        'total_purchase_amount' => $invoices->sum('total_purchase_amount'),
                        'total_vendor_commission' => $invoices->sum('total_vendor_commission_amount'),
                        'total_vendor_payable'  => $invoices->sum(fn ($ci) => $ci->totalVendorPayable()),
                    ];
                })
                ->sortByDesc('total_purchase_amount')
                ->values();
        }

        /* ================= CUSTOMER WISE =================
         * Grouped by customer — sale volume, customer commission charged
         * to them, other expenses passed through, and net receivable.
         */
        if ($tab === 'CW') {
            $query = CommissionInvoice::with('customer')
                ->whereBetween('invoice_date', [$from, $to]);

            if ($status) $query->where('status', $status);

            $customerWise = $query->get()
                ->groupBy('customer_id')
                ->map(function ($invoices) {
                    $customerName = $invoices->first()->customer->name ?? 'Unknown Customer';

                    return (object)[
                        'customer_name'             => $customerName,
                        'count'                     => $invoices->count(),
                        'total_sale_amount'         => $invoices->sum('total_sale_amount'),
                        'total_customer_commission' => $invoices->sum('total_customer_commission_amount'),
                        'total_other_expenses'      => $invoices->sum('total_other_expenses'),
                        'total_customer_receivable' => $invoices->sum(fn ($ci) => $ci->totalCustomerReceivable()),
                    ];
                })
                ->sortByDesc('total_sale_amount')
                ->values();
        }

        /* ================= STATUS OVERVIEW ================= */
        if ($tab === 'STA') {
            $query = CommissionInvoice::whereBetween('invoice_date', [$from, $to]);

            if ($vendorId)   $query->where('vendor_id', $vendorId);
            if ($customerId) $query->where('customer_id', $customerId);

            $invoices = $query->get();

            foreach ([
                CommissionInvoice::STATUS_PENDING,
                CommissionInvoice::STATUS_IN_TRANSIT,
                CommissionInvoice::STATUS_DELIVERED,
            ] as $statusKey) {
                $group = $invoices->where('status', $statusKey);

                $statusOverview->push((object)[
                    'status'                => $statusKey,
                    'label'                 => ucwords(str_replace('_', ' ', $statusKey)),
                    'count'                 => $group->count(),
                    'total_purchase_amount' => $group->sum('total_purchase_amount'),
                    'total_sale_amount'     => $group->sum('total_sale_amount'),
                    'total_gross_weight'    => $group->sum('total_gross_weight'),
                    'total_vendor_commission' => $group->sum('total_vendor_commission_amount'),
                ]);
            }
        }

        /* ================= OUTSTANDING (DELIVERED) =================
         * Commission has no partial-payment engine of its own — Vendor
         * Payable / Customer Receivable are settled via the general
         * Payment Voucher system, not tracked here. This tab simply
         * lists every Delivered invoice's full payable/receivable so
         * you can see what's sitting there; it does not know what's
         * actually been paid outside these vouchers.
         */
        if ($tab === 'OUT') {
            $query = CommissionInvoice::with(['vendor', 'customer'])
                ->where('status', CommissionInvoice::STATUS_DELIVERED);

            if ($request->filled('from_date') && $request->filled('to_date')) {
                $query->whereBetween('delivered_at', [$from, $to]);
            }
            if ($vendorId)   $query->where('vendor_id', $vendorId);
            if ($customerId) $query->where('customer_id', $customerId);

            $outstanding = $query->orderBy('delivered_at')->get()->map(function ($ci) {
                return (object)[
                    'id'                  => $ci->id,
                    'invoice_no'          => $ci->invoice_no,
                    'delivered_at'        => $ci->delivered_at,
                    'vendor_name'         => $ci->vendor->name ?? '',
                    'customer_name'       => $ci->customer->name ?? '',
                    'vendor_payable'      => $ci->totalVendorPayable(),
                    'customer_receivable' => $ci->totalCustomerReceivable(),
                    'due_date'            => $ci->dueDate(),
                    'days_since_delivery' => $ci->delivered_at ? Carbon::parse($ci->delivered_at)->diffInDays(Carbon::now()) : null,
                ];
            });
        }

        /* ================= OTHER EXPENSES ================= */
        if ($tab === 'EXP') {
            $query = CommissionInvoiceExpense::with(['commissionInvoice.vendor', 'payeeAccount'])
                ->whereHas('commissionInvoice', function ($q) use ($from, $to, $vendorId, $customerId) {
                    $q->whereBetween('invoice_date', [$from, $to]);
                    if ($vendorId)   $q->where('vendor_id', $vendorId);
                    if ($customerId) $q->where('customer_id', $customerId);
                });

            $expenseReport = $query->get()->map(function ($exp) {
                $payTo = $exp->paid_by === 'vendor'
                    ? ($exp->commissionInvoice->vendor->name ?? '-')
                    : ($exp->payeeAccount->name ?? '—');

                return (object)[
                    'date'        => $exp->commissionInvoice->invoice_date ?? null,
                    'invoice_no'  => $exp->commissionInvoice->invoice_no ?? '',
                    'type'        => $exp->typeLabel(),
                    'description' => $exp->description,
                    'amount'      => (float) $exp->amount,
                    'paid_by'     => $exp->paidByLabel(),
                    'payable_to'  => $payTo,
                ];
            })->sortByDesc('date')->values();
        }

        return view('reports.commission_reports', compact(
            'tab', 'from', 'to', 'status', 'vendorId', 'customerId',
            'vendors', 'customers',
            'commissionRegister', 'vendorWise', 'customerWise',
            'statusOverview', 'outstanding', 'expenseReport'
        ));
    }
}