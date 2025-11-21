<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GstOutputLedger extends Model
{
    protected $fillable = [
        'sales_bill_id',
        'sales_bill_line_id',
        'product_id',
        'gst_rate_id',
        'cgst',
        'sgst',
        'igst',
        'total_gst'
    ];

    public function bill()
    {
        return $this->belongsTo(SalesBill::class, 'sales_bill_id');
    }
}
