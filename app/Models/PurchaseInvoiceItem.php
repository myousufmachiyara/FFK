<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * MERGE this into your existing PurchaseInvoiceItem model (from
 * purchase_module.zip) — don't blind-overwrite if you've since added
 * anything else to it.
 */
class PurchaseInvoiceItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'purchase_invoice_id',
        'item_id',
        'variation_id',
        'unit',                 // legacy, unused by new forms — left for compatibility
        'packing_unit_id',
        'wt_per_packing',
        'quantity',              // number of packing units (bags)
        'gross_weight',
        'net_weight',
        'rate_per_40kg',
        'price',                 // rate per KG (computed)
        'amount',                // price * net_weight

        // Receiving / shortage
        'dispatched_quantity',       // legacy bag-count snapshot, informational only now
        'received_quantity',         // legacy, unused by new receive flow
        'received_packing_qty',      // bags actually received — informational
        'received_net_weight',       // KG actually received — this drives costing
        'short_quantity',            // legacy
        'short_weight',              // net_weight - received_net_weight
        'shortage_reason',
        'allocated_additional_cost', // this item's share of Other Expenses
    ];

    protected $casts = [
        'wt_per_packing'             => 'decimal:3',
        'quantity'                   => 'decimal:2',
        'gross_weight'                => 'decimal:3',
        'net_weight'                  => 'decimal:3',
        'rate_per_40kg'               => 'decimal:2',
        'price'                       => 'decimal:4',
        'amount'                      => 'decimal:2',
        'received_packing_qty'        => 'decimal:2',
        'received_net_weight'         => 'decimal:3',
        'short_weight'                => 'decimal:3',
        'allocated_additional_cost'   => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'item_id');
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
     * Server-side truth for the whole weight-costing chain — never trust
     * client-submitted gross_weight/rate_per_kg/amount.
     *
     *   gross_weight = wt_per_packing * quantity
     *   net_weight   = user override, or gross_weight if not provided
     *   rate_per_kg  = rate_per_40kg / kg_per_maund
     *   amount       = rate_per_kg * net_weight
     */
    public static function computeLine(
        float $wtPerPacking,
        float $quantity,
        ?float $netWeightOverride,
        float $ratePer40kg,
        int $kgPerMaund
    ): array {
        $grossWeight = round($wtPerPacking * $quantity, 3);
        $netWeight   = ($netWeightOverride !== null && $netWeightOverride > 0) ? $netWeightOverride : $grossWeight;
        $ratePerKg   = $kgPerMaund > 0 ? round($ratePer40kg / $kgPerMaund, 4) : 0;
        $amount      = round($ratePerKg * $netWeight, 2);

        return [
            'grossWeight' => $grossWeight,
            'netWeight'   => $netWeight,
            'ratePerKg'   => $ratePerKg,
            'amount'      => $amount,
        ];
    }

    /** Final landed cost PER KG after Other Expenses are allocated in. */
    public function landedUnitCost(): float
    {
        $weight = (float) ($this->received_net_weight ?? 0);
        if ($weight <= 0) {
            return (float) $this->price;
        }
        return ((float) $this->price * $weight + (float) $this->allocated_additional_cost) / $weight;
    }

    public function dispatchedValue(): float
    {
        return (float) ($this->net_weight ?? 0) * (float) $this->price;
    }

    public function receivedValue(): float
    {
        return (float) ($this->received_net_weight ?? 0) * (float) $this->price;
    }
}
