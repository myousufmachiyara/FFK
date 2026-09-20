<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleReturn extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'sale_invoice_id', 'return_no', 'return_date', 'customer_id',
        'reason', 'total_weight', 'total_amount', 'total_cogs', 'created_by',
    ];

    protected $casts = [
        'return_date'  => 'date',
        'total_weight' => 'decimal:3',
        'total_amount' => 'decimal:2',
        'total_cogs'   => 'decimal:2',
    ];

    public function saleInvoice() { return $this->belongsTo(SaleInvoice::class); }
    public function customer() { return $this->belongsTo(ChartOfAccounts::class, 'customer_id'); }
    public function items() { return $this->hasMany(SaleReturnItem::class); }

    public function vouchers()
    {
        return Voucher::where('reference', 'like', "SR-{$this->id}-%")->get();
    }
}