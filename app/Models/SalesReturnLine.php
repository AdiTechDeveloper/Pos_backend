<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesReturnLine extends Model
{
    protected $fillable = [
        'sales_return_id',
        'sales_bill_line_id',
        'product_id',
        'inventory_id',
        'qty',
        'rate',
        'taxable_amount',
        'amount',
        'cgst',
        'sgst',
        'igst',
        'total_gst',
        'cogs_recovered',
        'is_damaged',
    ];

    public function salesReturn()
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id', 'id');
    }

    public function salesBillLines()
    {
        return $this->belongsToMany(SalesBillLine::class, 'sales_bill_line_id', 'id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function inventory()
    {
        return $this->belongsToMany(Inventory::class, 'inventory_id', 'id');
    }
}
