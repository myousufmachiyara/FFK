<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommissionReturn extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'commission_invoice_id', 'return_no', 'return_date', 'return_type', 'vendor_id', 'customer_id',
        'reason', 'total_weight', 'total_sale_value', 'total_purchase_value', 'total_vendor_commission',
        'total_customer_commission', 'created_by',
    ];

    protected $casts = [
        'return_date'                 => 'date',
        'total_weight'                => 'decimal:3',
        'total_sale_value'            => 'decimal:2',
        'total_purchase_value'        => 'decimal:2',
        'total_vendor_commission'     => 'decimal:2',
        'total_customer_commission'   => 'decimal:2',
    ];

    public function commissionInvoice() { return $this->belongsTo(CommissionInvoice::class); }
    public function vendor() { return $this->belongsTo(ChartOfAccounts::class, 'vendor_id'); }
    public function customer() { return $this->belongsTo(ChartOfAccounts::class, 'customer_id'); }
    public function items() { return $this->hasMany(CommissionReturnItem::class); }

    public function vouchers()
    {
        return Voucher::where('reference', 'like', "CR-{$this->id}-%")->get();
    }

    /** The net amount this return actually reduces the Customer's receivable by. */
    public function netCustomerReduction(): float
    {
        return round((float) $this->total_sale_value, 2);
    }

    /**
     * The net amount this return actually reduces Vendor Payable by —
     * only meaningful for a Vendor-scenario return. Purchase value goes
     * back out (Vendor no longer owed for goods they're taking back),
     * while Vendor Commission comes back in (they're not earning it on
     * this portion) — net effect is the difference between the two,
     * matching exactly how Vendor Payable is built up in the first place
     * (Purchase Amount minus Vendor Commission).
     */
    public function netVendorReduction(): float
    {
        return round((float) $this->total_purchase_value - (float) $this->total_vendor_commission, 2);
    }

    public function isVendorReturn(): bool
    {
        return $this->return_type === 'vendor';
    }

    public function isStockInReturn(): bool
    {
        return $this->return_type === 'stock_in';
    }

    public function returnTypeLabel(): string
    {
        return $this->isStockInReturn() ? 'Stock In (FFK)' : 'Returned to Vendor';
    }
}