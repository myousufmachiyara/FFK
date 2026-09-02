<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * MERGE this into your existing PurchaseInvoice model — preserve
 * anything else already added since the original purchase_module.zip.
 */
class PurchaseInvoice extends Model
{
    use SoftDeletes;

    const STATUS_PENDING    = 'pending';
    const STATUS_IN_TRANSIT = 'in_transit';
    const STATUS_RECEIVED   = 'received';

    protected $fillable = [
        'invoice_no',
        'vendor_id',
        'invoice_date',
        'bill_no',       // = "Vendor Bill Number"
        'ref_no',
        'remarks',
        'status',
        'bilty_no',
        'received_at',
        'received_by',
        'bilty_charges',      // legacy, unused going forward
        'labor_charges',      // legacy, unused going forward
        'other_charges',      // legacy, unused going forward
        'total_amount',
        'total_quantity',
        'total_weight',
        'total_other_expenses',
        'net_amount',
        'created_by',
    ];

    protected $casts = [
        'invoice_date'          => 'date',
        'received_at'           => 'datetime',
        'total_amount'          => 'decimal:2',
        'total_quantity'        => 'decimal:2',
        'total_weight'          => 'decimal:3',
        'total_other_expenses'  => 'decimal:2',
        'net_amount'            => 'decimal:2',
    ];

    public function vendor()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'vendor_id');
    }

    public function items()
    {
        return $this->hasMany(PurchaseInvoiceItem::class, 'purchase_invoice_id');
    }

    public function expenses()
    {
        return $this->hasMany(PurchaseInvoiceExpense::class, 'purchase_invoice_id');
    }

    public function attachments()
    {
        return $this->hasMany(PurchaseInvoiceAttachment::class, 'purchase_invoice_id');
    }

    public function statusHistories()
    {
        return $this->hasMany(PurchaseStatusHistory::class)->latest();
    }

    public function receivedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'received_by');
    }

    public function vouchers()
    {
        return Voucher::where('reference', 'like', "PI-{$this->id}-%")->get();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isInTransit(): bool
    {
        return $this->status === self::STATUS_IN_TRANSIT;
    }

    public function isReceived(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }

    /** Paid by FFK — total Other Expenses (Bilty/Labor/Weighing/etc), from the dynamic expense list. */
    public function totalAdditionalCharges(): float
    {
        return (float) $this->total_other_expenses;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING    => 'Pending',
            self::STATUS_IN_TRANSIT => 'In Transit',
            self::STATUS_RECEIVED   => 'Received',
            default                 => ucfirst($this->status ?? 'Unknown'),
        };
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING    => 'badge bg-secondary',
            self::STATUS_IN_TRANSIT => 'badge bg-warning text-dark',
            self::STATUS_RECEIVED   => 'badge bg-success',
            default                 => 'badge bg-light text-dark',
        };
    }
}
