<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'address',
        'phone',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_staff');
    }
}
