<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnLine extends Model
{
    protected $fillable = [
        'purchase_return_id',
        'purchase_bill_line_id',
        'product_id',
        'qty',
        'free',
        'rate',
        'gst_rate_id',
        'hsn_code',
        'taxable_value',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'line_total',
    ];

    protected $casts = [
        'qty' => 'integer',
        'free' => 'integer',
        'rate' => 'decimal:2',
        'taxable_value' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function return(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
