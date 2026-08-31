<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChartOfAccounts extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'shoa_id',
        'name',
        'account_code',
        'account_type',
        'receivables',
        'payables',
        'credit_limit',
        'opening_date',
        'remarks',
        'address',
        'contact_no',
        'created_by',
        'updated_by',
    ];

    // Define the relationship with SubHeadOfAccounts (belongs to)
    public function subHeadOfAccount()
    {
        return $this->belongsTo(SubHeadOfAccounts::class, 'shoa_id', 'id');
    }

    public function purchaseInvoices()
    {
        return $this->hasMany(PurchaseInvoice::class, 'vendor_id');
    }

}
