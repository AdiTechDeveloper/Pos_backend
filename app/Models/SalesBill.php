<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesBill extends Model
{
    protected $fillable = [
        'store_id',
        'branch_id',
        'user_id',
        'bill_no',
        'subtotal',
        'total_gst',
        'total_amount',
        'total_saved',
        'total_cogs',
        'total_profit',
        'cash_received',
        'balance_return',
        'payment_status',
        'last_idempotency_key',
        'bill_status',
        'created_by'
    ];

    public function lines()
    {
        return $this->hasMany(SalesBillLine::class);
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
}
