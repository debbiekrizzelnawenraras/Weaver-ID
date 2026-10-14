<?php

namespace Database\Seeders;

use App\Models\Association;
use App\Models\Product;
use App\Models\User;
use App\Models\Weaver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('weaver.seed_password') ?: Str::random(16);

        $association = Association::create([
            'name' => "Rose Ann\u{2019}s Loom Art Gallery",
            'municipality' => 'Sagada',
            'status' => 'active',
        ]);

        User::create([
            'name' => 'DTI System Administrator',
            'email' => 'admin@weaver-id.test',
            'password' => $password,
            'role' => 'admin',
            'status' => 'active',
        ]);

        User::create([
            'name' => 'DTI Staff User',
            'email' => 'staff@weaver-id.test',
            'password' => $password,
            'role' => 'staff',
            'municipality' => 'Sagada',
            'status' => 'active',
        ]);

        User::create([
            'name' => "Rose Ann\u{2019}s Loom Art Gallery",
            'email' => 'association@weaver-id.test',
            'password' => $password,
            'role' => 'association',
            'association_id' => $association->id,
            'status' => 'active',
        ]);

        $weaver1 = Weaver::create([
            'association_id' => $association->id,
            'name' => 'Sample Member 1 (Weaver)',
            'proprietor' => 'Placeholder Owner 1',
            'municipality' => 'Sagada',
            'status' => 'active',
        ]);

        $weaver2 = Weaver::create([
            'association_id' => $association->id,
            'name' => 'Sample Member 2 (Weaver)',
            'proprietor' => 'Placeholder Owner 2',
            'municipality' => 'Sagada',
            'status' => 'active',
        ]);

        $weaver3 = Weaver::create([
            'association_id' => $association->id,
            'name' => 'Sample Member 3 (Sewer)',
            'proprietor' => 'Placeholder Owner 3',
            'municipality' => 'Sagada',
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

        $this->command?->info("Demo accounts (admin@, staff@, association@weaver-id.test) use password: {$password}");
    }
}
