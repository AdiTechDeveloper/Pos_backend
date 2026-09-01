<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    protected $fillable = [
        'store_id',
        'sku',
        'barcode',
        'name',
        'brand_id',
        'category_id',
        'hsn_code',
        'gst_rate_id',
        'mrp',
        'selling_price',
        'cost_price',
        'created_by',
        'updated_by',
        'stock',
        'gst_inclusive',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function gstRate()
    {
        return $this->belongsTo(GstRate::class);
    }

    public function inventories()
    {
        return $this->hasMany(Inventory::class, 'product_id', 'id');
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'branch_id');
    }

    /**
     * Get the total available stock by summing inventory batches.
     */
    // public function getTotalStockAttribute()
    // {
    //     return $this->inventories()->sum(DB::raw('qty - sold_qty'));
    // }
}
