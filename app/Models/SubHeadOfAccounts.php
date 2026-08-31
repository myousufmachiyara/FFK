<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubHeadOfAccounts extends Model
{
    use HasFactory, SoftDeletes;

    // Define the table associated with the model (optional if it follows Laravel's naming convention)
    protected $table = 'sub_head_of_accounts';

    // Specify the fillable attributes for mass assignment
    protected $fillable = ['hoa_id', 'name'];

    // Define the relationship with the HeadOfAccounts model
    public function headOfAccount()
    {
        return $this->belongsTo(HeadOfAccounts::class, 'hoa_id', 'id');
    }
}