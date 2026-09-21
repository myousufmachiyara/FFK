<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionInvoiceItem extends Model
{
    protected $fillable = [
        'commission_invoice_id',
        'product_id',
        'variation_id',
        'unit_id',                  // legacy, unused by new forms
        'packing_unit_id',
        'wt_per_packing',
        'quantity',                  // number of packing units
        'gross_weight',
        'net_weight',

        'purchase_rate_per_40kg',
        'purchase_price',            // rate per KG (computed)
        'purchase_total',            // purchase_price * net_weight

        'sale_rate_per_40kg',
        'sale_price',                // rate per KG (computed)
        'sale_total',                // sale_price * net_weight

        'vendor_commission_percentage',
        'vendor_commission_amount',
        'customer_commission_percentage',
        'customer_commission_amount',
    ];

    protected $casts = [
        'wt_per_packing'                   => 'decimal:3',
        'quantity'                          => 'decimal:2',
        'gross_weight'                       => 'decimal:3',
        'net_weight'                         => 'decimal:3',
        'purchase_rate_per_40kg'             => 'decimal:2',
        'purchase_price'                     => 'decimal:4',
        'purchase_total'                     => 'decimal:2',
        'sale_rate_per_40kg'                 => 'decimal:2',
        'sale_price'                         => 'decimal:4',
        'sale_total'                         => 'decimal:2',
        'vendor_commission_percentage'       => 'decimal:2',
        'vendor_commission_amount'           => 'decimal:2',
        'customer_commission_percentage'     => 'decimal:2',
        'customer_commission_amount'         => 'decimal:2',
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
     * Server-side truth for the whole line — never trust client-submitted
     * weights/rates/amounts/commissions.
     *
     *   gross_weight = wt_per_packing * quantity
     *   net_weight   = user override, or gross_weight if not provided
     *   purchase/sale rate per kg = rate_per_40kg / kg_per_maund
     *   purchase/sale total = rate_per_kg * net_weight
     *   vendor commission   = purchase_total * vendor_commission_pct / 100
     *   customer commission = sale_total * customer_commission_pct / 100
     */
    public static function computeLine(array $in, int $kgPerMaund): array
    {
        $wtPerPacking = (float) $in['wt_per_packing'];
        $quantity     = (float) $in['quantity'];
        $netOverride  = isset($in['net_weight']) && $in['net_weight'] !== '' ? (float) $in['net_weight'] : null;

        $grossWeight = round($wtPerPacking * $quantity, 3);
        $netWeight   = ($netOverride !== null && $netOverride > 0) ? $netOverride : $grossWeight;

        $purchaseRate40 = (float) ($in['purchase_rate_per_40kg'] ?? 0);
        $saleRate40      = (float) ($in['sale_rate_per_40kg'] ?? 0);

        // Two-way rate entry — the per-kg box on the form is live, so if the
        // user typed into it, that figure is authoritative and the 40 kg one
        // is re-derived from it (and vice versa).
        $purchaseRateKgIn = isset($in['purchase_rate_per_kg']) && $in['purchase_rate_per_kg'] !== ''
            ? (float) $in['purchase_rate_per_kg'] : null;
        $saleRateKgIn     = isset($in['sale_rate_per_kg']) && $in['sale_rate_per_kg'] !== ''
            ? (float) $in['sale_rate_per_kg'] : null;

        [$purchaseRate40, $purchasePriceKg] = PurchaseInvoiceItem::resolveRates($purchaseRate40, $purchaseRateKgIn, $kgPerMaund);
        [$saleRate40, $salePriceKg]          = PurchaseInvoiceItem::resolveRates($saleRate40, $saleRateKgIn, $kgPerMaund);

        $purchaseTotal = round($purchasePriceKg * $netWeight, 2);
        $saleTotal     = round($salePriceKg * $netWeight, 2);

        $vendorPct   = (float) ($in['vendor_commission_percentage'] ?? 0);
        $customerPct = (float) ($in['customer_commission_percentage'] ?? 0);

        $vendorCommissionAmount   = round($purchaseTotal * $vendorPct / 100, 2);
        $customerCommissionAmount = round($saleTotal * $customerPct / 100, 2);

        return [
            'grossWeight'               => $grossWeight,
            'netWeight'                 => $netWeight,
            'purchaseRate40'            => $purchaseRate40,
            'saleRate40'                => $saleRate40,
            'purchasePriceKg'           => $purchasePriceKg,
            'purchaseTotal'             => $purchaseTotal,
            'salePriceKg'               => $salePriceKg,
            'saleTotal'                 => $saleTotal,
            'vendorCommissionAmount'    => $vendorCommissionAmount,
            'customerCommissionAmount' => $customerCommissionAmount,
        ];
    }
}
