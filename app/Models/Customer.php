<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'name', 'mobile', 'add1', 'add2', 'area', 'city', 'opening_balance',
    ];
}
