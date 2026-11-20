<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Panel directive (19 Nov 2026): the system no longer keeps members (weavers and sewers).
 * Every product now belongs directly to its association. Each existing product keeps the
 * association of the member it was registered under, so no product or tag is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('association_id')->nullable()->after('id')
                ->constrained('associations')->cascadeOnDelete();
        });

        DB::table('products')->update([
            'association_id' => DB::raw('(SELECT weavers.association_id FROM weavers WHERE weavers.id = products.weaver_id)'),
        ]);

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('weaver_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('association_id')->nullable(false)->change();
        });

        Schema::dropIfExists('weavers');
    }

    /**
     * Rolls back to the old shape with one placeholder member per association,
     * because the member names were removed for good.
     */
    public function down(): void
    {
        Schema::create('weavers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained('associations')->cascadeOnDelete();
            $table->string('name');
            $table->string('proprietor')->nullable();
            $table->string('municipality');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        foreach (DB::table('associations')->get() as $association) {
            DB::table('weavers')->insert([
                'association_id' => $association->id,
                'name' => 'Members of '.$association->name,
                'municipality' => $association->municipality ?? '',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('weaver_id')->nullable()->after('id')->constrained('weavers')->cascadeOnDelete();
        });

        DB::table('products')->update([
            'weaver_id' => DB::raw('(SELECT weavers.id FROM weavers WHERE weavers.association_id = products.association_id)'),
        ]);

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('association_id');
        });
    }
};
