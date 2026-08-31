<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseStatusHistory extends Model
{
    protected $fillable = [
        'purchase_invoice_id',
        'from_status',
        'to_status',
        'changed_by',
        'remarks',
    ];

    public function purchaseInvoice()
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'changed_by');
    }
}
