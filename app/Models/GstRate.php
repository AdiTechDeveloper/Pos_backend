<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GstRate extends Model
{
    protected $fillable = [
        'store_id',
        'rate',
        'description',
        'active',
        'created_by',
        'updated_by'
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
