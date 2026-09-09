<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'branch_id', 'name', 'mobile', 'add1', 'add2', 'area', 'city', 'opening_balance',
        'loyalty_points',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
