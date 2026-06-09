<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesBillLine extends Model
{
    protected $fillable = [
        'sales_bill_id',
        'product_id',
        'branch_id',
        'inventory_id',
        'qty',
        'rate',
        'selling_price',
        'original_price',
        'override_price',
        'is_price_overridden',
        'taxable_amount',
        'amount',
        'cgst',
        'sgst',
        'igst',
        'total_gst',
        'cogs',
        'profit',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function inventory()
    {
        return $this->belongsTo(Inventory::class);
    }

    public function gstRate()
    {
        return $this->belongsTo(GstRate::class);
    }

    public function bill()
    {
        return $this->belongsTo(SalesBill::class, 'sales_bill_id');
    }
}