<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $fillable = [
        'product_id',
        'branch_id',
        'purchase_bill_id',
        'purchase_line_id',
        'qty',
        'free',
        'batch_no',
        'expiry_date',
        'rate',
        'amount'
    ];


    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function line()
    {
        return $this->belongsTo(PurchaseLine::class, 'id');
    }
}
