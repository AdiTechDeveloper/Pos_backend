<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesReturn extends Model
{
    protected $fillable = [
        'store_id',
        'branch_id',
        'sales_bill_id',
        'return_no',
        'customer_id',
        'subtotal',
        'total_gst',
        'total_refund_amount',
        'total_cogs_recovered',
        'refund_type',
        'processed_by',
        'last_idempotency_key_return',
        'notes',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id', 'id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'id');
    }

    public function salesBill()
    {
        return $this->belongsTo(SalesBill::class, 'sales_bill_id', 'id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function returnLines()
    {
        return $this->hasMany(SalesReturnLine::class, 'sales_return_id', 'id');
    }
}
