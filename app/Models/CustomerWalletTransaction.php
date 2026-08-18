<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerWalletTransaction extends Model
{
    protected $fillable = [
        'customer_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'source_type',
        'source_id',
        'note',
        'created_by',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
