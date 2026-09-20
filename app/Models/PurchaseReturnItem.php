<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturnItem extends Model
{
    protected $fillable = [
        'purchase_return_id',
        'purchase_invoice_item_id',
        'item_id',
        'variation_id',
        'quantity',
        'net_weight',
        'price',
        'amount',
    ];

    protected $casts = [
        'quantity'   => 'decimal:3',
        'net_weight' => 'decimal:3',
        'price'      => 'decimal:4',
        'amount'     => 'decimal:2',
    ];

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function originalItem()
    {
        return $this->belongsTo(PurchaseInvoiceItem::class, 'purchase_invoice_item_id');
    }

    public function item()
    {
        return $this->belongsTo(Product::class, 'item_id');
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }
}