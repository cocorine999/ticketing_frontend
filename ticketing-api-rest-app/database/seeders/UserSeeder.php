<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('users')->delete();

        // Get role IDs
        $superAdminRole = Role::where('slug', 'super-admin')->first();
        $organizerRole = Role::where('slug', 'organizer')->first();
        $agentRole = Role::where('slug', 'agent-de-controle')->first();
        $comptableRole = Role::where('slug', 'comptable')->first();
        $participantRole = Role::where('slug', 'participant')->first();

        if (!$superAdminRole || !$organizerRole || !$agentRole || !$comptableRole || !$participantRole) {
            $this->command->error('One or more roles not found. Please run RoleSeeder first.');
            return;
        }

        // Create Super Admin User
        User::create([
            'id' => Str::uuid(),
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'type' => 'super-admin', // Added type
            'role_id' => $superAdminRole->id,
            'remember_token' => Str::random(10),
        ]);

        // Create Organizer User
        User::create([
            'id' => Str::uuid(),
            'name' => 'Event Organizer',
            'email' => 'organizer@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'type' => 'organizer', // Added type
            'role_id' => $organizerRole->id,
            'remember_token' => Str::random(10),
        ]);

        // Create Agent de Controle User
        User::create([
            'id' => Str::uuid(),
            'name' => 'Control Agent',
            'email' => 'agent@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'type' => 'agent-de-controle', // Added type
            'role_id' => $agentRole->id,
            'remember_token' => Str::random(10),
        ]);

        // Create Comptable User
        User::create([
            'id' => Str::uuid(),
            'name' => 'Accountant',
            'email' => 'comptable@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'type' => 'comptable',
            'role_id' => $comptableRole->id,
            'remember_token' => Str::random(10),
        ]);

        // Create Participant User
        User::create([
            'id' => Str::uuid(),
            'name' => 'Event Participant',
            'email' => 'participant@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'type' => 'participant',
            'role_id' => $participantRole->id,
            'remember_token' => Str::random(10),
        ]);
    }
}
