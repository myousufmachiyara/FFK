<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleInvoiceItem extends Model
{
    protected $fillable = [
        'sale_invoice_id',
        'product_id',
        'variation_id',
        'packing_unit_id',
        'wt_per_packing',
        'quantity',        // number of packing units
        'gross_weight',
        'net_weight',
        'rate_per_40kg',
        'sale_price',      // rate per KG (computed)
        'discount',        // percentage, applied to the rate before multiplying
        'total',           // line total
        'unit_cost',       // COGS cost per KG (snapshot from Purchase's landed cost)
    ];

    protected $casts = [
        'wt_per_packing' => 'decimal:3',
        'quantity'       => 'decimal:2',
        'gross_weight'   => 'decimal:3',
        'net_weight'     => 'decimal:3',
        'rate_per_40kg'  => 'decimal:2',
        'sale_price'     => 'decimal:4',
        'discount'       => 'decimal:2',
        'total'          => 'decimal:2',
        'unit_cost'      => 'decimal:4',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }

    public function packingUnit()
    {
        return $this->belongsTo(MeasurementUnit::class, 'packing_unit_id');
    }

    /**
     * Server-side truth for the weight-costing chain — never trust
     * client-submitted gross_weight/rate_per_kg/total.
     *
     *   gross_weight = wt_per_packing * quantity
     *   net_weight   = user override, or gross_weight if not provided
     *   rate_per_kg  = rate_per_40kg / kg_per_maund
     *   total        = (rate_per_kg - rate_per_kg * discount%) * net_weight
     */
    public static function computeLine(
        float $wtPerPacking,
        float $quantity,
        ?float $netWeightOverride,
        float $ratePer40kg,
        float $discountPct,
        int $kgPerMaund
    ): array {
        $grossWeight = round($wtPerPacking * $quantity, 3);
        $netWeight   = ($netWeightOverride !== null && $netWeightOverride > 0) ? $netWeightOverride : $grossWeight;
        $ratePerKg   = $kgPerMaund > 0 ? round($ratePer40kg / $kgPerMaund, 4) : 0;
        $discountedRate = $ratePerKg - ($ratePerKg * $discountPct / 100);
        $total       = round($discountedRate * $netWeight, 2);

        return [
            'grossWeight' => $grossWeight,
            'netWeight'   => $netWeight,
            'ratePerKg'   => $ratePerKg,
            'total'       => $total,
        ];
    }

    public function lineCost(): float
    {
        return round((float) $this->unit_cost * (float) $this->net_weight, 2);
    }
}