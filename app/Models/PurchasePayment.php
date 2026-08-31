<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchasePayment extends Model
{
    protected $fillable = [
        'purchase_bill_id',
        'amount',
        'method',
        'reference',
        'payment_date',
        'status',
        'updated_by',
        'remarks',
    ];

    public function bill()
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }
}
