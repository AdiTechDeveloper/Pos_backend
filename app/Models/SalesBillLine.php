<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesBillLine extends Model
{
    protected $fillable = [
        'sales_bill_id',
        'product_id',
        'branch_id',
        'inventory_id',
        'qty',
        'rate',
        'amount',
        'cogs',
        'profit',
        'cgst',
        'sgst',
        'igst',
        'total_gst'
    ];

    public function bill()
    {
        return $this->belongsTo(SalesBill::class);
    }

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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
