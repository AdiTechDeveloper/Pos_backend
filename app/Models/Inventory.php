<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Inventory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'branch_id',
        'purchase_bill_id',
        'purchase_line_id',
        'batch_barcode',
        'mrp',
        'selling_price',
        'cost_price',
        'qty',
        'sold_qty',
        'expired_qty',
        'expired_at',
        'free',
        'batch_no',
        'expiry_date',
        'rate',
        'amount',
        'is_opening',
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

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'id');
    }

    public function line()
    {
        return $this->belongsTo(PurchaseLine::class, 'purchase_line_id');
    }
}
