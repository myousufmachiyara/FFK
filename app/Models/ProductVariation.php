<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class ProductVariation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'selling_price',
        'stock_quantity',   // purely transactional (bags)
        'stock_weight',     // purely transactional (kg)
        'opening_stock',    // fixed pre-system balance (bags)
        'opening_weight',   // fixed pre-system balance (kg)
        'opening_rate',     // cost per kg for the opening balance (optional)
    ];

    protected $casts = [
        'selling_price'  => 'decimal:2',
        'stock_quantity' => 'decimal:3',
        'stock_weight'   => 'decimal:3',
        'opening_stock'  => 'decimal:3',
        'opening_weight' => 'decimal:3',
        'opening_rate'   => 'decimal:4',
    ];

    /* ----------------- Relationships ----------------- */

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseInvoiceItem::class, 'variation_id');
    }

    public function saleItems()
    {
        return $this->hasMany(SaleInvoiceItem::class, 'variation_id');
    }

    public function attributeValues()
    {
        return $this->belongsToMany(AttributeValue::class, 'product_variation_attribute_values')->withTimestamps();
    }

    public function values()
    {
        return $this->hasMany(ProductVariationAttributeValue::class);
    }

    /* ----------------- Stock ----------------- */

    /** Bags available = opening_stock + stock_quantity. */
    public function availableStock(): float
    {
        return round((float) $this->opening_stock + (float) $this->stock_quantity, 3);
    }

    /** Kg available = opening_weight + stock_weight. */
    public function availableWeight(): float
    {
        return round((float) $this->opening_weight + (float) $this->stock_weight, 3);
    }

    /**
     * Weighted-average landed cost per KG across EVERY Received purchase
     * of this variation (not just the latest one) — same purchase can
     * have happened at different rates on different invoices, so this
     * blends them all by weight. Landed cost = the purchase price per kg
     * PLUS that item's allocated share of Other Expenses from Receiving.
     *
     * Opening stock only contributes to this average if you've set an
     * opening_rate for it — otherwise it's weight/qty-only (counted in
     * available stock) with no cost impact on the average.
     *
     * Returns 0 if there's no purchase history AND no opening_rate —
     * callers should treat 0 as "no cost basis available" and decide
     * their own fallback (e.g. Sale falls back to its own sale rate).
     */
    public function averageLandedCost(): float
    {
        $totals = DB::table('purchase_invoice_items')
            ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
            ->where('purchase_invoice_items.variation_id', $this->id)
            ->where('purchase_invoices.status', 'received')
            ->whereNull('purchase_invoices.deleted_at')
            ->whereNotNull('purchase_invoice_items.received_net_weight')
            ->selectRaw('SUM(purchase_invoice_items.received_net_weight * purchase_invoice_items.price + COALESCE(purchase_invoice_items.allocated_additional_cost, 0)) as total_cost')
            ->selectRaw('SUM(purchase_invoice_items.received_net_weight) as total_weight')
            ->first();

        $totalCost   = (float) ($totals->total_cost ?? 0);
        $totalWeight = (float) ($totals->total_weight ?? 0);

        if ((float) $this->opening_weight > 0 && (float) $this->opening_rate > 0) {
            $totalCost   += (float) $this->opening_weight * (float) $this->opening_rate;
            $totalWeight += (float) $this->opening_weight;
        }

        return $totalWeight > 0 ? round($totalCost / $totalWeight, 4) : 0.0;
    }
}