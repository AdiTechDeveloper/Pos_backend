<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerLoyaltyTransaction extends Model
{
    protected $fillable = [
        'customer_id',
        'sales_bill_id',
        'type',
        'points',
        'balance_before',
        'balance_after',
        'note',
        'created_by',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesBill()
    {
        return $this->belongsTo(SalesBill::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
