<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductCategory extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'code'];

    public function products()
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function productions()
    {
        return $this->hasMany(Production::class, 'category_id');
    }
}
