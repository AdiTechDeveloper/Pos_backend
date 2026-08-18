<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseBill extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'store_id',
        'branch_id',
        'supplier_id',
        'bill_no',
        'inward_no',
        'inward_sequence',
        'bill_date',
        'taxable_value',
        'is_lost',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'cess_amount',
        'total_tax',
        'total_amount',
        'received',
        'created_by',
        'updated_by',
        'tax_type',
        'settlement_amount',
        'notes',
        'deleted_by',
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

    public function itcEntries()
    {
        return $this->hasMany(ItcEntry::class);
    }

    public function inventory()
    {
        return $this->hasMany(Inventory::class);
    }

    protected static function booted()
    {
        static::deleting(function ($bill) {
            if (! $bill->isForceDeleting()) {
                $bill->lines()->delete();
                $bill->itcEntries()->delete();
                $bill->inventory()->delete();
            }
        });

        static::restoring(function ($bill) {
            $bill->lines()->withTrashed()->restore();
            $bill->itcEntries()->withTrashed()->restore();
            $bill->inventory()->withTrashed()->restore();
        });
    }
}
