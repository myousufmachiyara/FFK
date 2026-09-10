<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleInvoice extends Model
{
    protected $fillable = [
        'invoice_no',
        'date',
        'account_id',        // Customer (ChartOfAccounts)
        'vendor_id',         // optional — only for expense routing
        'type',              // cash | credit
        'credit_days',
        'remarks',
        'discount',          // flat invoice-level discount
        'net_amount',        // items only
        'total_weight',
        'total_gross_weight',
        'total_other_expenses',
        'amount_received',
        'created_by',
    ];

    protected $casts = [
        'date'                  => 'date',
        'discount'              => 'decimal:2',
        'net_amount'            => 'decimal:2',
        'total_weight'          => 'decimal:3',
        'total_gross_weight'    => 'decimal:3',
        'total_other_expenses'  => 'decimal:2',
        'amount_received'       => 'decimal:2',
    ];

    public function account()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'account_id');
    }

    public function customer()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'account_id');
    }

    public function vendor()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'vendor_id');
    }

    public function items()
    {
        return $this->hasMany(SaleInvoiceItem::class, 'sale_invoice_id');
    }

    public function expenses()
    {
        return $this->hasMany(SaleInvoiceExpense::class, 'sale_invoice_id');
    }

    public function vouchers()
    {
        return Voucher::where('reference', 'like', "SI-{$this->id}-%")->get();
    }

    /** Sale Invoice Summary: Total Item Amount + Total Expense Amount. */
    public function totalBillAmount(): float
    {
        return round((float) $this->net_amount + (float) $this->total_other_expenses, 2);
    }

    public function remainingBalance(): float
    {
        return round($this->totalBillAmount() - (float) $this->amount_received, 2);
    }

    public function isCredit(): bool
    {
        return $this->type === 'credit';
    }

    public function dueDate(): ?\Carbon\Carbon
    {
        if (!$this->isCredit() || !$this->credit_days) {
            return null;
        }
        return \Carbon\Carbon::parse($this->date)->addDays((int) $this->credit_days);
    }
}