<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItcEntry extends Model
{
    protected $fillable = [
        'purchase_bill_id',
        'purchase_line_id',
        'product_id',
        'cgst',
        'sgst',
        'igst',
        'total_itc',
        'created_by'
    ];
}
