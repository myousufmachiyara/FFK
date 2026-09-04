<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionInvoiceExpense extends Model
{
    const TYPE_PACKING       = 'packing';
    const TYPE_LOCAL_CARTAGE = 'local_cartage';
    const TYPE_MISC          = 'misc';

    const PAID_BY_VENDOR  = 'vendor';
    const PAID_BY_COMPANY = 'company';

    protected $fillable = [
        'commission_invoice_id',
        'expense_type',
        'description',
        'amount',
        'paid_by',
        'payee_account_id', // only used when paid_by = company
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function commissionInvoice()
    {
        return $this->belongsTo(CommissionInvoice::class);
    }

    public function payeeAccount()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'payee_account_id');
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

    public function paidByLabel(): string
    {
        return $this->paid_by === self::PAID_BY_VENDOR ? 'Vendor' : 'Company (FFK)';
    }
}
