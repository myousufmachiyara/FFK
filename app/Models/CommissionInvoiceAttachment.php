<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionInvoiceAttachment extends Model
{
    protected $fillable = [
        'commission_invoice_id',
        'file_path',
        'original_name',
        'file_type',
        'stage',
    ];

    public function commissionInvoice()
    {
        return $this->belongsTo(CommissionInvoice::class);
    }
}
