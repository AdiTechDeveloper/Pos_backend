<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockExpiryAlert extends Model
{
    protected $fillable = [
        'purchase_line_id',
        'product_id',
        'branch_id',
        'expiry_date',
        'days_left',
        'severity',
        'alert_date',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function purchaseLine()
    {
        return $this->belongsTo(PurchaseLine::class);
    }
}
