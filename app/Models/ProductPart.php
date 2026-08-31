<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductPart extends Model
{
    use SoftDeletes;

    protected $table = 'product_parts';

    protected $fillable = [
        'product_id',
        'part_id',
        'part_variation_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    /**
     * Finished Product (FG)
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Part Product (Raw / Semi FG)
     */
    public function part()
    {
        return $this->belongsTo(Product::class, 'part_id');
    }

    /**
     * Part Variation (optional)
     */
    public function partVariation()
    {
        return $this->belongsTo(ProductVariation::class, 'part_variation_id');
    }
}
