<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseInvoiceExpense extends Model
{
    const TYPE_BILTY             = 'bilty';
    const TYPE_LABOR             = 'labor';
    const TYPE_WEIGHING          = 'weighing';
    const TYPE_LOADING_UNLOADING = 'loading_unloading';
    const TYPE_TRANSPORT         = 'transport';
    const TYPE_MISC              = 'misc';

    protected $fillable = [
        'purchase_invoice_id',
        'expense_type',
        'description',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function purchaseInvoice()
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function typeLabel(): string
    {
        return match ($this->expense_type) {
            self::TYPE_BILTY             => 'Bilty',
            self::TYPE_LABOR             => 'Labor',
            self::TYPE_WEIGHING          => 'Weighing',
            self::TYPE_LOADING_UNLOADING => 'Loading/Unloading',
            self::TYPE_TRANSPORT         => 'Transport',
            self::TYPE_MISC              => 'Miscellaneous',
            default                      => ucfirst(str_replace('_', ' ', $this->expense_type)),
        };
    }
}
