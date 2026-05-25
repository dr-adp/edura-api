<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'super_admin', 'display_name' => 'Super Admin'],
            ['name' => 'institute_admin', 'display_name' => 'Institute Admin'],
            ['name' => 'teacher', 'display_name' => 'Teacher'],
            ['name' => 'student', 'display_name' => 'Student'],
            ['name' => 'parent', 'display_name' => 'Parent'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['name' => $role['name']],
                ['display_name' => $role['display_name']]
            );
        }
    }
}