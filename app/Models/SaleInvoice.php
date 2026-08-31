<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * NOTE: Merge into your existing SaleInvoice model — I don't have your
 * original file, only what the controller/blade views implied.
 */
class SaleInvoice extends Model
{
    protected $fillable = [
        'invoice_no',
        'date',
        'account_id',   // Customer (ChartOfAccounts)
        'type',         // cash | credit
        'remarks',
        'discount',     // flat invoice-level discount
        'net_amount',
        'amount_received',
        'created_by',
    ];

    protected $casts = [
        'date'             => 'date',
        'discount'         => 'decimal:2',
        'net_amount'       => 'decimal:2',
        'amount_received'  => 'decimal:2',
    ];

    public function account()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'account_id');
    }

    // Kept for compatibility if anything refers to ->customer instead of ->account
    public function customer()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'account_id');
    }

    public function items()
    {
        return $this->hasMany(SaleInvoiceItem::class, 'sale_invoice_id');
    }

    public function vouchers()
    {
        return Voucher::where('reference', 'like', "SI-{$this->id}-%")->get();
    }

    public function remainingBalance(): float
    {
        return round((float) $this->net_amount - (float) $this->amount_received, 2);
    }
}
