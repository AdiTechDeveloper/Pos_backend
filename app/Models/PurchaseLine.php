<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseLine extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'purchase_bill_id',
        'product_id',
        'gst_rate_id',
        'qty',
        'free_qty',
        'purchase_rate',
        'mrp',
        'selling_price',
        'discount_type',
        'discount',
        'amount',
        'hsn_code',
        'batch_no',
        'expiry_date',
        'taxable_value',
        'cgst',
        'sgst',
        'igst',
        'total_gst',
        'is_opening',
    ];

    public function bill()
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function gstRate()
    {
        return $this->belongsTo(GstRate::class, 'gst_rate_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function inventory()
    {
        return $this->hasOne(Inventory::class, 'purchase_line_id', 'id');
    }
}
