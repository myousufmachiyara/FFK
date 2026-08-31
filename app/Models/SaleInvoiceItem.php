<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * NOTE: Merge into your existing SaleInvoiceItem model.
 */
class SaleInvoiceItem extends Model
{
    protected $fillable = [
        'sale_invoice_id',
        'product_id',
        'variation_id',
        'sale_price',
        'quantity',
        'discount',    // percentage, per line
        'total',
        'unit_cost',   // snapshot of cost at time of sale, for COGS
    ];

    protected $casts = [
        'sale_price' => 'decimal:2',
        'quantity'   => 'decimal:2',
        'discount'   => 'decimal:2',
        'total'      => 'decimal:2',
        'unit_cost'  => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }

    public function discountedUnitPrice(): float
    {
        $pct = (float) ($this->discount ?? 0);
        return (float) $this->sale_price - ((float) $this->sale_price * $pct / 100);
    }

    public function lineTotal(): float
    {
        return round($this->discountedUnitPrice() * (float) $this->quantity, 2);
    }

    public function lineCost(): float
    {
        return round((float) $this->unit_cost * (float) $this->quantity, 2);
    }
}
