<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'sku',
        'selling_price',
        'stock_quantity',
    ];

    /* ----------------- Relationships ----------------- */

    // Belongs to main product
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // 🔹 ADD THIS: Relationship to Purchase Items
    public function purchaseItems()
    {
        return $this->hasMany(PurchaseInvoiceItem::class, 'variation_id');
    }

    // 🔹 ADD THIS: Relationship to Sale Items
    public function saleItems()
    {
        return $this->hasMany(SaleInvoiceItem::class, 'variation_id');
    }
    
    // Belongs to many attribute values (e.g. color, size)
    public function attributeValues()
    {
        return $this->belongsToMany(AttributeValue::class, 'product_variation_attribute_values')->withTimestamps();
    }

    // Pivot model for extra handling (if needed)
    public function values()
    {
        return $this->hasMany(ProductVariationAttributeValue::class);
    }

}
