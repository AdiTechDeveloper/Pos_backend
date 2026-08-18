<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesBillPayment extends Model
{
    protected $fillable = [
        'sales_bill_id',
        'method',
        'amount',
        'transaction_id',
        'gateway',
        'status',
        'created_at',
    ];

    public function salesBill()
    {
        return $this->belongsTo(\App\Models\SalesBill::class, 'sales_bill_id');
    }
}
