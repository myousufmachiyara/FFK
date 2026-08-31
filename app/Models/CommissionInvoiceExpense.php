<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionInvoiceExpense extends Model
{
    const TYPE_PACKING       = 'packing';
    const TYPE_LOCAL_CARTAGE = 'local_cartage';
    const TYPE_MISC          = 'misc';

    protected $fillable = [
        'commission_invoice_id',
        'expense_type',
        'description',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function commissionInvoice()
    {
        return $this->belongsTo(CommissionInvoice::class);
    }

    public function typeLabel(): string
    {
        return match ($this->expense_type) {
            self::TYPE_PACKING       => 'Packing',
            self::TYPE_LOCAL_CARTAGE => 'Local Cartage',
            self::TYPE_MISC          => 'Miscellaneous',
            default                  => ucfirst($this->expense_type),
        };
    }
}
