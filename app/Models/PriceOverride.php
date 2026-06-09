<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceOverride extends Model
{
    protected $fillable = [
        'sale_bill_id',
        'sale_bill_line_id',
        'product_id',
        'branch_id',
        'original_price',
        'override_price',
        'difference',
        'qty',
        'total_loss',
        'overridden_by',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function bill()
    {
        // Double check if your class is SalesBill or SaleBill to match your project files
        return $this->belongsTo(SalesBill::class, 'sale_bill_id');
    }

    public function overriddenBy()
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    // THIS WAS MISSING: Added branch relationship
    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}