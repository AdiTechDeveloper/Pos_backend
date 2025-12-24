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
        'sold_qty',
        'expired_qty',
        'expired_at',
        'free',
        'batch_no',
        'expiry_date',
        'rate',
        'amount'
    ];

    protected $appends = ['available_qty'];

    public function getAvailableQtyAttribute()
    {
        return $this->qty - $this->sold_qty - $this->expired_qty;
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function line()
    {
        return $this->belongsTo(PurchaseLine::class, 'id');
    }
}
