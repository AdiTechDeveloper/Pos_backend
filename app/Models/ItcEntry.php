<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ItcEntry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'purchase_bill_id',
        'purchase_line_id',
        'product_id',
        'cgst',
        'sgst',
        'igst',
        'total_itc',
        'created_by',
    ];
}
