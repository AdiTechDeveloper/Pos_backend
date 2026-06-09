<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPayment extends Model
{
    protected $fillable = [
        'customer_id', 'sales_bill_id', 'amount', 'method', 'reference_no', 'payment_date',
    ];
}
