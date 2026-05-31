<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]
            ->forgetCachedPermissions();

        $permissions = [
            'manage institutions',
            'manage subscriptions',
            'manage departments',
            'manage batches',
            'manage teachers',
            'manage students',
            'manage parents',
            'manage courses',
            'manage lessons',
            'manage resources',
            'manage live classes',
            'mark attendance',
            'manage assignments',
            'submit assignments',
            'evaluate assignments',
            'manage quizzes',
            'attempt quizzes',
            'view gradebook',
            'manage gradebook',
            'view reports',
            'manage users',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $superAdmin = Role::updateOrCreate(
            ['name' => 'super-admin', 'guard_name' => 'web'],
            ['display_name' => 'Super Admin']
        );

        $institutionAdmin = Role::updateOrCreate(
            ['name' => 'institution-admin', 'guard_name' => 'web'],
            ['display_name' => 'Institution Admin']
        );

        $teacher = Role::updateOrCreate(
            ['name' => 'teacher', 'guard_name' => 'web'],
            ['display_name' => 'Teacher']
        );

        $student = Role::updateOrCreate(
            ['name' => 'student', 'guard_name' => 'web'],
            ['display_name' => 'Student']
        );

        $parent = Role::updateOrCreate(
            ['name' => 'parent', 'guard_name' => 'web'],
            ['display_name' => 'Parent']
        );

        $superAdmin->syncPermissions(Permission::all());

        $institutionAdmin->syncPermissions([
            'manage departments',
            'manage batches',
            'manage teachers',
            'manage students',
            'manage parents',
            'manage courses',
            'manage lessons',
            'manage resources',
            'manage live classes',
            'mark attendance',
            'manage assignments',
            'evaluate assignments',
            'manage quizzes',
            'view gradebook',
            'view reports',
        ]);

        $teacher->syncPermissions([
            'manage courses',
            'manage lessons',
            'manage resources',
            'manage live classes',
            'mark attendance',
            'manage assignments',
            'evaluate assignments',
            'manage quizzes',
            'view reports',
        ]);

        $student->syncPermissions([
            'submit assignments',
            'attempt quizzes',
            'view gradebook',
        ]);

        $parent->syncPermissions([
            'view gradebook',
        ]);
    }
}
