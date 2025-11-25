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
        'status'
    ];

    public function bill()
    {
        return $this->belongsTo(SalesBill::class, 'sales_bill_id');
    }
}
