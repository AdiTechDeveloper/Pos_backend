<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReturn extends Model
{
    protected $fillable = [
        'purchase_bill_id',
        'supplier_id',
        'branch_id',
        'return_date',
        'total_taxable',
        'total_gst',
        'total_amount',
        'created_by',
    ];


    protected $casts = [
        'return_date' => 'date',
        'total_taxable' => 'decimal:2',
        'total_gst' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function purchaseBill()
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
