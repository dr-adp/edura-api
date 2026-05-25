<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::where('name', 'super_admin')->first();

        User::updateOrCreate(
            ['email' => 'admin@edura.com'],
            [
                'name' => 'EDURA Super Admin',
                'password' => Hash::make('Admin@12345'),
                'role_id' => $role?->id,
            ]
        );
    }
}