<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * NOTE: Merge into your existing PurchaseInvoiceItem model — preserve any
 * relationships/logic already there beyond what's added below.
 */
class PurchaseInvoiceItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'purchase_invoice_id',
        'item_id',
        'variation_id',
        'quantity',       // Ordered Quantity
        'unit',
        'price',
        'dispatched_quantity',
        'received_quantity',
        'short_quantity',
        'shortage_reason',
        'allocated_additional_cost',
    ];

    protected $casts = [
        'quantity'                  => 'decimal:2',
        'price'                     => 'decimal:2',
        'dispatched_quantity'       => 'decimal:2',
        'received_quantity'         => 'decimal:2',
        'short_quantity'            => 'decimal:2',
        'allocated_additional_cost' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'item_id');
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }

    public function orderedValue(): float
    {
        return (float) $this->quantity * (float) $this->price;
    }

    public function dispatchedValue(): float
    {
        return (float) ($this->dispatched_quantity ?? $this->quantity) * (float) $this->price;
    }

    public function receivedValue(): float
    {
        return (float) ($this->received_quantity ?? 0) * (float) $this->price;
    }

    /** Final landed unit cost after additional charges are allocated in. */
    public function landedUnitCost(): float
    {
        $qty = (float) ($this->received_quantity ?? 0);
        if ($qty <= 0) {
            return (float) $this->price;
        }
        return ((float) $this->price * $qty + (float) $this->allocated_additional_cost) / $qty;
    }
}
