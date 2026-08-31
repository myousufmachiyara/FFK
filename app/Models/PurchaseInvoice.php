<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * NOTE: Merge this into your existing PurchaseInvoice model rather than
 * blindly overwriting it — I don't have your original file, only what
 * the controller/blade views implied. Preserve any other relationships
 * or accessors already defined there.
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
        'bilty_charges',
        'labor_charges',
        'other_charges',
        'total_amount',
        'total_quantity',
        'net_amount',
        'created_by',
    ];

    protected $casts = [
        'invoice_date'  => 'date',
        'received_at'   => 'datetime',
        'bilty_charges' => 'decimal:2',
        'labor_charges' => 'decimal:2',
        'other_charges' => 'decimal:2',
        'total_amount'  => 'decimal:2',
        'net_amount'    => 'decimal:2',
    ];

    public function vendor()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'vendor_id');
    }

    public function items()
    {
        return $this->hasMany(PurchaseInvoiceItem::class, 'purchase_invoice_id');
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
        // All vouchers generated for this invoice, across every stage.
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

    public function totalAdditionalCharges(): float
    {
        return (float) $this->bilty_charges + (float) $this->labor_charges + (float) $this->other_charges;
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
