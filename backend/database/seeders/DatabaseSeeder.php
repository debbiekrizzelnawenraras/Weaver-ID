<?php

namespace Database\Seeders;

use App\Models\Association;
use App\Models\Product;
use App\Models\User;
use App\Models\Weaver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $association = Association::create([
            'name' => 'Montañosa Weavers',
            'municipality' => 'Sagada',
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

        $weaver1 = Weaver::create([
            'association_id' => $association->id,
            'name' => 'Sagada Weaving',
            'proprietor' => 'Rose Ann Wangdali',
            'municipality' => 'Sagada',
            'status' => 'active',
        ]);

        $weaver2 = Weaver::create([
            'association_id' => $association->id,
            'name' => 'Gandang Handwoven',
            'proprietor' => 'Virginia Omaweng',
            'municipality' => 'Sagada',
            'status' => 'active',
        ]);

        $weaver3 = Weaver::create([
            'association_id' => $association->id,
            'name' => 'Besao Loomweaving',
            'proprietor' => 'Elena Dao',
            'municipality' => 'Besao',
            'status' => 'active',
        ]);

        Product::create([
            'weaver_id' => $weaver1->id,
            'title' => 'Pinagpagan Pattern Blanket',
            'category' => 'Blanket / Textile',
            'status' => 'active',
        ]);

        Product::create([
            'weaver_id' => $weaver2->id,
            'title' => 'Inabel Table Runner',
            'category' => 'Home Decor',
            'status' => 'active',
        ]);

        Product::create([
            'weaver_id' => $weaver3->id,
            'title' => 'Woven Tote Bag',
            'category' => 'Bags & Accessories',
            'status' => 'active',
        ]);
    }
}