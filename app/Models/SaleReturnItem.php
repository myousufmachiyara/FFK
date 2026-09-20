<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleReturnItem extends Model
{
    protected $fillable = [
        'sale_return_id', 'sale_invoice_item_id', 'product_id', 'variation_id',
        'qty', 'net_weight', 'price', 'amount', 'unit_cost', 'cogs_amount',
    ];

    protected $casts = [
        'qty'         => 'decimal:3',
        'net_weight'  => 'decimal:3',
        'price'       => 'decimal:4',
        'amount'      => 'decimal:2',
        'unit_cost'   => 'decimal:4',
        'cogs_amount' => 'decimal:2',
    ];

    public function saleReturn() { return $this->belongsTo(SaleReturn::class); }
    public function originalItem() { return $this->belongsTo(SaleInvoiceItem::class, 'sale_invoice_item_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function variation() { return $this->belongsTo(ProductVariation::class); }
}