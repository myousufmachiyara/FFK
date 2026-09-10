<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleInvoiceExpense extends Model
{
    const TYPE_LOCAL_CARTAGE = 'local_cartage';
    const TYPE_PACKAGING     = 'packaging';
    const TYPE_PLASTIC_BAGS  = 'plastic_bags';
    const TYPE_BARDANA       = 'bardana';
    const TYPE_MISC          = 'misc';
    const TYPE_TULAI         = 'tulai';
    const TYPE_OTHERS        = 'others';

    const PAID_BY_VENDOR  = 'vendor';
    const PAID_BY_COMPANY = 'company';

    protected $fillable = [
        'sale_invoice_id',
        'expense_type',
        'description',
        'amount',
        'paid_by',
        'payee_account_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function saleInvoice()
    {
        return $this->belongsTo(SaleInvoice::class);
    }

    public function payeeAccount()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'payee_account_id');
    }

    public function typeLabel(): string
    {
        return match ($this->expense_type) {
            self::TYPE_LOCAL_CARTAGE => 'Local Cartage',
            self::TYPE_PACKAGING     => 'Packaging',
            self::TYPE_PLASTIC_BAGS  => 'Plastic Bags',
            self::TYPE_BARDANA       => 'Bardana',
            self::TYPE_MISC          => 'Miscellaneous',
            self::TYPE_TULAI         => 'Tulai',
            self::TYPE_OTHERS        => 'Others',
            default                  => ucfirst(str_replace('_', ' ', $this->expense_type)),
        };
    }

    public function paidByLabel(): string
    {
        return $this->paid_by === self::PAID_BY_VENDOR ? 'Vendor' : 'Company (FFK)';
    }
}