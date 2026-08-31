<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionStatusHistory extends Model
{
    protected $fillable = [
        'commission_invoice_id',
        'from_status',
        'to_status',
        'changed_by',
        'remarks',
    ];

    public function commissionInvoice()
    {
        return $this->belongsTo(CommissionInvoice::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'changed_by');
    }
}
