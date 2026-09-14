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
        'barcode',          // FIX: was missing — ProductController has been
                            // writing this since barcode support was added,
                            // but mass assignment was silently dropping it
                            // because it was never in $fillable.
        'selling_price',
        'stock_quantity',   // purely transactional — only ever touched by
                            // Purchase Receive / Sale / their reversals, or
                            // the stock:recalculate command. Never hand-edit.
        'stock_weight',     // kg equivalent of stock_quantity, maintained
                            // in lockstep alongside it.
        'opening_stock',    // one-time pre-system stock balance (bags) —
                            // permanently separate from stock_quantity so
                            // stock:recalculate never wipes it out.
    ];

    protected $casts = [
        'selling_price'  => 'decimal:2',
        'stock_quantity' => 'decimal:3',
        'stock_weight'   => 'decimal:3',
        'opening_stock'  => 'decimal:3',
    ];

    /* ----------------- Relationships ----------------- */

    // Belongs to main product
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Relationship to Purchase Items
    public function purchaseItems()
    {
        return $this->hasMany(PurchaseInvoiceItem::class, 'variation_id');
    }

    // Relationship to Sale Items
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

    /* ----------------- Stock ----------------- */

    /**
     * The single source of truth for "how many bags are actually
     * available" — opening_stock (fixed, pre-system balance) +
     * stock_quantity (live, transactional balance). Always use this
     * instead of reading stock_quantity alone wherever you need to know
     * what's really in stock.
     */
    public function availableStock(): float
    {
        return round((float) $this->opening_stock + (float) $this->stock_quantity, 3);
    }
}