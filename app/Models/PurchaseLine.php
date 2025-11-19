<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseLine extends Model
{
    protected $fillable = [
        'purchase_bill_id',
        'product_id',
        'gst_rate_id',
        'qty',
        'free_qty',
        'purchase_rate',
        'discount_type',
        'discount',
        'hsn_code',
        'batch_no',
        'expiry_date',
        'taxable_value',
        'cgst',
        'sgst',
        'igst'
    ];

    public function gstRate()
    {
        return $this->belongsTo(GstRate::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
