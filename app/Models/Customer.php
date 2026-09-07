<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'branch_id', 'name', 'mobile', 'add1', 'add2', 'area', 'city', 'opening_balance',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
