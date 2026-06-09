<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesBill extends Model
{
    protected $fillable = [
        'store_id',
        'branch_id',
        'user_id',
        'customer_id',
        'bill_no',
        'subtotal',
        'total_gst',
        'total_amount',
        'paid_amount',
        'due_amount',
        'total_saved',
        'total_cogs',
        'total_profit',
        'cash_received',
        'balance_return',
        'payment_status',
        'last_idempotency_key_store',
        'last_idempotency_key_payment',
        'bill_status',
        'created_by',
    ];

    public function lines()
    {
        return $this->hasMany(SalesBillLine::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payments()
    {
        return $this->hasMany(SalesBillPayment::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
