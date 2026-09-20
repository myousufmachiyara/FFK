<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseReturn extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'purchase_invoice_id',
        'return_no',
        'return_date',
        'vendor_id',
        'reason',
        'total_weight',
        'total_amount',
        'created_by',
    ];

    protected $casts = [
        'return_date'  => 'date',
        'total_weight' => 'decimal:3',
        'total_amount' => 'decimal:2',
    ];

    public function purchaseInvoice()
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function vendor()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'vendor_id');
    }

    public function items()
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function vouchers()
    {
        return Voucher::where('reference', 'like', "PR-{$this->id}-%")->get();
    }
}