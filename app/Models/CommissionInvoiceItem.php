<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionInvoiceItem extends Model
{
    protected $fillable = [
        'commission_invoice_id',
        'product_id',
        'variation_id',
        'unit_id',
        'quantity',
        'weight',
        'purchase_price',
        'sale_price',
        'commission_percentage',
        'commission_amount',
        'purchase_total',
        'sale_total',
    ];

    protected $casts = [
        'quantity'               => 'decimal:2',
        'weight'                 => 'decimal:2',
        'purchase_price'         => 'decimal:2',
        'sale_price'             => 'decimal:2',
        'commission_percentage'  => 'decimal:2',
        'commission_amount'      => 'decimal:2',
        'purchase_total'         => 'decimal:2',
        'sale_total'             => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }

    public function unit()
    {
        return $this->belongsTo(MeasurementUnit::class, 'unit_id');
    }

    /** Server-side truth for line calculations — never trust client-submitted totals. */
    public static function computeLine(float $qty, float $purchasePrice, float $salePrice, float $commissionPct): array
    {
        $purchaseTotal    = round($qty * $purchasePrice, 2);
        $saleTotal        = round($qty * $salePrice, 2);
        $commissionAmount = round($saleTotal * $commissionPct / 100, 2);

        return compact('purchaseTotal', 'saleTotal', 'commissionAmount');
    }
}
