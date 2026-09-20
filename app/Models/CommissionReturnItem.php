<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionReturnItem extends Model
{
    protected $fillable = [
        'commission_return_id', 'commission_invoice_item_id', 'product_id', 'variation_id',
        'qty', 'net_weight', 'sale_value', 'vendor_commission', 'customer_commission',
    ];

    protected $casts = [
        'qty'                 => 'decimal:3',
        'net_weight'          => 'decimal:3',
        'sale_value'          => 'decimal:2',
        'vendor_commission'   => 'decimal:2',
        'customer_commission' => 'decimal:2',
    ];

    public function commissionReturn() { return $this->belongsTo(CommissionReturn::class); }
    public function originalItem() { return $this->belongsTo(CommissionInvoiceItem::class, 'commission_invoice_item_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function variation() { return $this->belongsTo(ProductVariation::class); }
}