<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = Role::firstOrCreate(['name'=>'admin']);
        $user  = Role::firstOrCreate(['name'=>'user']);

        Permission::firstOrCreate(['name'=>'posts.view']);
        Permission::firstOrCreate(['name'=>'posts.create']);
        Permission::firstOrCreate(['name'=>'posts.update']);
        Permission::firstOrCreate(['name'=>'posts.delete']);

        $admin->givePermissionTo(Permission::all());
        $user->givePermissionTo(['posts.view']);
    }
}
