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
        'mrp',              // ✅ add
        'selling_price',    // ✅ add
        'discount_type',
        'discount',
        'amount',           // ✅ add
        'hsn_code',
        'batch_no',
        'expiry_date',
        'taxable_value',
        'cgst',
        'sgst',
        'igst',
        'total_gst',        // ✅ add
        'is_opening',       // ✅ add
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