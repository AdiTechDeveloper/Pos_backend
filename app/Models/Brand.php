<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    protected $fillable = ['store_id', 'name', 'description', 'created_by', 'updated_by'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
