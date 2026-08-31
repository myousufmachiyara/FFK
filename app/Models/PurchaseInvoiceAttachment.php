<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseInvoiceAttachment extends Model
{
    protected $fillable = [
        'purchase_invoice_id',
        'file_path',
        'original_name',
        'file_type',
        'stage', // pending | in_transit | received
    ];

    public function purchaseInvoice()
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }
}