<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseBill extends Model
{
    protected $fillable = [
        'store_id',
        'branch_id',
        'supplier_id',
        'bill_no',
        'bill_date',
        'taxable_value',
        "is_lost",
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'cess_amount',
        'total_tax',
        'total_amount',
        'received',
        'created_by',
        'updated_by'
    ];

     public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function lines()
    {
        return $this->hasMany(PurchaseLine::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
