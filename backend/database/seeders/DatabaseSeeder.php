<?php

namespace Database\Seeders;

use App\Models\Association;
use App\Models\NfcTag;
use App\Models\Product;
use App\Models\User;
use App\Models\TagBatch;
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

        $admin = User::create([
            'name' => 'DTI System Administrator',
            'email' => 'admin@weaver-id.test',
            'password' => $password,
            'role' => 'admin',
            'status' => 'active',
        ]);

        $staff = User::create([
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

        $product1 = Product::create([
            'association_id' => $association->id,
            'title' => 'Pinagpagan Pattern Blanket',
            'category' => Product::FABRIC,
            'status' => 'active',
        ]);

        $product2 = Product::create([
            'association_id' => $association->id,
            'title' => 'Inabel Table Runner',
            'category' => Product::FABRIC,
            'status' => 'active',
        ]);

        Product::create([
            'association_id' => $association->id,
            'title' => 'Woven Tote Bag',
            'category' => Product::FABRIC,
            'status' => 'active',
        ]);

        // Fixed demo tags so a fresh database can always show all three outcomes.
        // 04DE0000000003 is deliberately left unregistered. 04DE0000000011 to 13 show the
        // inventory: two sent to the association and not yet linked, one still in stock.
        $batch = TagBatch::create([
            'code' => 'DEMO-2026-01',
            'municipality' => 'Sagada',
            'supplier' => 'Sample supplier',
            'received_on' => now()->toDateString(),
            'received_by' => $staff->id,
        ]);

        NfcTag::create([
            'uid' => '04DE0000000001',
            'tag_batch_id' => $batch->id,
            'association_id' => $association->id,
            'sent_by' => $staff->id,
            'sent_at' => now(),
            'product_id' => $product1->id,
            'status' => 'active',
            'issued_by' => $staff->id,
            'issued_at' => now(),
        ]);

        NfcTag::create([
            'uid' => '04DE0000000002',
            'tag_batch_id' => $batch->id,
            'association_id' => $association->id,
            'sent_by' => $staff->id,
            'sent_at' => now(),
            'product_id' => $product2->id,
            'status' => 'revoked',
            'issued_by' => $staff->id,
            'issued_at' => now(),
        ]);

        foreach (['04DE0000000011', '04DE0000000012'] as $uid) {
            NfcTag::create([
                'uid' => $uid,
                'tag_batch_id' => $batch->id,
                'association_id' => $association->id,
                'sent_by' => $staff->id,
                'sent_at' => now(),
                'status' => 'sent',
            ]);
        }

        NfcTag::create([
            'uid' => '04DE0000000013',
            'tag_batch_id' => $batch->id,
            'status' => 'in_stock',
        ]);

        // Delivery lists for the wrong-center demo, none received yet: 04DE0000000014 is assigned to
        // Sagada (the demo staff can receive it), 04DE0000000015 to Bontoc (the demo staff is refused
        // and the admin gets an alert).
        foreach (['04DE0000000014' => 'Sagada', '04DE0000000015' => 'Bontoc'] as $uid => $municipality) {
            \App\Models\TagAssignment::create([
                'uid' => $uid, 'municipality' => $municipality, 'delivery_ref' => 'DL-DEMO-01', 'assigned_by' => $admin->id,
            ]);
        }

        $this->command?->info("Demo accounts (admin@, staff@, association@weaver-id.test) use password: {$password}");
        $this->command?->info('Demo tags: 04DE0000000001 genuine, 04DE0000000002 revoked, 04DE0000000003 unregistered.');
        $this->command?->info('Inventory: 04DE0000000011 and 12 sent to the association, 04DE0000000013 in stock; 04DE0000000014 assigned to Sagada, 04DE0000000015 to Bontoc (wrong-center demo).');
    }
}
