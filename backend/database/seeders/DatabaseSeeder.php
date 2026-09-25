<?php

namespace Database\Seeders;

use App\Models\Association;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $association = Association::create([
            'name' => 'Test Weaving Association',
            'municipality' => 'Bontoc',
            'status' => 'active',
        ]);

        User::create([
            'name' => 'DTI System Administrator',
            'email' => 'admin@weaver-id.test',
            'password' => Hash::make('Admin123!'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        User::create([
            'name' => 'DTI Staff User',
            'email' => 'staff@weaver-id.test',
            'password' => Hash::make('Staff123!'),
            'role' => 'staff',
            'status' => 'active',
        ]);

        User::create([
            'name' => 'Association Hub User',
            'email' => 'association@weaver-id.test',
            'password' => Hash::make('Association123!'),
            'role' => 'association',
            'association_id' => $association->id,
            'status' => 'active',
        ]);
    }
}