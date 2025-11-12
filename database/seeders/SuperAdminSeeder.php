<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            [
                'name' => 'Super Admin',
                'username' => 'superadmin',
                'password' => Hash::make('123456'),
                'role' => 'superadmin',
                'is_active' => true,
            ]
        );
    }
}
