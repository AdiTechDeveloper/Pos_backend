<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RegisterShift extends Model
{
    use HasFactory;

    // Define the table name if it's not the plural form of the model
    protected $table = 'register_shifts';

    // Allow these fields to be filled via the controller
    protected $fillable = [
        'branch_id',
        'user_id',
        'opening_balance',
        'closing_balance',
        'status',
        'opened_at',
        'closed_at'
    ];

    // Relationship: A shift belongs to a user (staff/cashier)
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relationship: A shift belongs to a branch
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}