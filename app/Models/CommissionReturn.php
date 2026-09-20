<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommissionReturn extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'commission_invoice_id', 'return_no', 'return_date', 'vendor_id', 'customer_id',
        'reason', 'total_weight', 'total_sale_value', 'total_vendor_commission',
        'total_customer_commission', 'created_by',
    ];

    protected $casts = [
        'return_date'                 => 'date',
        'total_weight'                => 'decimal:3',
        'total_sale_value'            => 'decimal:2',
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
}