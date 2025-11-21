<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = [
        'store_id',
        'name',
        'gstin',
        'contact',
        'address',
        'state',
        'created_by',
        'updated_by'
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
