<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Voucher extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'voucher_type', // payment, receipt, journal, or any custom type
        'date',
        'ac_dr_sid',
        'ac_cr_sid',
        'amount',
        'remarks',
        'reference',
        'description',
        'attachments',
    ];

    protected $casts = [
        'attachments' => 'array',
    ];

    public function debitAccount()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'ac_dr_sid', 'id');
    }

    public function creditAccount()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'ac_cr_sid', 'id');
    }

    public function scopeType($query, $type)
    {
        return $query->where('voucher_type', $type);
    }

}
