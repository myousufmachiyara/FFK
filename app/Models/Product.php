<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'subcategory_id',
        'name',
        'sku',
        'description',
        'opening_stock',
        'selling_price',
        'measurement_unit',
        'is_active',
    ];
    /* ----------------- Relationships ----------------- */

    // Belongs to category
    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function subcategory()
    {
        return $this->belongsTo(ProductSubcategory::class, 'subcategory_id');
    }

    // Has many variations
    public function variations()
    {
        return $this->hasMany(ProductVariation::class);
    }

    // Has many images
    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    // Belongs to measurement unit
    public function measurementUnit()
    {
        return $this->belongsTo(MeasurementUnit::class, 'measurement_unit');
    }

    public function purchaseInvoices() 
    {
        return $this->hasMany(PurchaseInvoiceItem::class, 'item_id');
    }

    public function saleInvoices() 
    {
        return $this->hasMany(SaleInvoiceItem::class, 'product_id');
    }
}
