<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAdvanceDeposit extends Model
{
    protected $fillable = [
        'customer_id',
        'branch_id',
        'amount',
        'method',
        'transaction_id',
        'received_by',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
