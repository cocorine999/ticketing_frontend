<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('role_permissions')->delete();
        DB::table('roles')->delete();

        $roles = [
            ['id' => Str::uuid(), 'name' => 'Super Admin', 'slug' => 'super-admin', 'guard_name' => 'web'],
            ['id' => Str::uuid(), 'name' => 'Organizer', 'slug' => 'organizer', 'guard_name' => 'web'],
            ['id' => Str::uuid(), 'name' => 'Agent de Controle', 'slug' => 'agent-de-controle', 'guard_name' => 'web'],
            ['id' => Str::uuid(), 'name' => 'Comptable', 'slug' => 'comptable', 'guard_name' => 'web'],
            ['id' => Str::uuid(), 'name' => 'Participant', 'slug' => 'participant', 'guard_name' => 'web'],
        ];

        foreach ($roles as $role) {
            Role::create($role);
        }
    }
}
