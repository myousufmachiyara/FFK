<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommissionInvoice extends Model
{
    use SoftDeletes;

    const STATUS_PENDING    = 'pending';
    const STATUS_IN_TRANSIT = 'in_transit';
    const STATUS_DELIVERED  = 'delivered';

    protected $fillable = [
        'invoice_no',
        'invoice_date',
        'vendor_id',
        'customer_id',
        'transport_name',
        'bilty_no',
        'vendor_bill_no',
        'ref_no',
        'remarks',
        'status',
        'total_quantity',
        'total_weight',
        'total_purchase_amount',
        'total_sale_amount',
        'total_commission_amount',          // combined (vendor + customer)
        'total_vendor_commission_amount',
        'total_customer_commission_amount',
        'total_other_expenses',
        'delivered_at',
        'delivered_by',
        'delivery_received_by_name',
        'delivery_remarks',
        'created_by',
    ];

    protected $casts = [
        'invoice_date'                      => 'date',
        'delivered_at'                       => 'datetime',
        'total_quantity'                     => 'decimal:2',
        'total_weight'                        => 'decimal:3',
        'total_purchase_amount'              => 'decimal:2',
        'total_sale_amount'                  => 'decimal:2',
        'total_commission_amount'            => 'decimal:2',
        'total_vendor_commission_amount'     => 'decimal:2',
        'total_customer_commission_amount'   => 'decimal:2',
        'total_other_expenses'               => 'decimal:2',
    ];

    public function vendor()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'vendor_id');
    }

    public function customer()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'customer_id');
    }

    public function items()
    {
        return $this->hasMany(CommissionInvoiceItem::class, 'commission_invoice_id');
    }

    public function expenses()
    {
        return $this->hasMany(CommissionInvoiceExpense::class, 'commission_invoice_id');
    }

    public function attachments()
    {
        return $this->hasMany(CommissionInvoiceAttachment::class, 'commission_invoice_id');
    }

    public function statusHistories()
    {
        return $this->hasMany(CommissionStatusHistory::class, 'commission_invoice_id')->latest();
    }

    public function deliveredBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'delivered_by');
    }

    public function vouchers()
    {
        return Voucher::where('reference', 'like', "CI-{$this->id}-%")->get();
    }

    public function isPending(): bool    { return $this->status === self::STATUS_PENDING; }
    public function isInTransit(): bool  { return $this->status === self::STATUS_IN_TRANSIT; }
    public function isDelivered(): bool  { return $this->status === self::STATUS_DELIVERED; }

    /**
     * Total the Vendor owes/is owed changes by commission — the Vendor
     * Payable posted at In Transit is total_purchase_amount; if vendor
     * commission applies, it's reduced at Delivered by that amount.
     */
    public function totalVendorPayable(): float
    {
        return round((float) $this->total_purchase_amount - (float) $this->total_vendor_commission_amount, 2);
    }

    /** Customer Receivable = Sale Amount + Other Expenses (unaffected by which commission leg applies). */
    public function totalCustomerReceivable(): float
    {
        return round((float) $this->total_sale_amount + (float) $this->total_other_expenses, 2);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING    => 'Pending',
            self::STATUS_IN_TRANSIT => 'In Transit',
            self::STATUS_DELIVERED  => 'Delivered',
            default                 => ucfirst($this->status ?? 'Unknown'),
        };
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING    => 'badge bg-secondary',
            self::STATUS_IN_TRANSIT => 'badge bg-warning text-dark',
            self::STATUS_DELIVERED  => 'badge bg-success',
            default                 => 'badge bg-light text-dark',
        };
    }
}
