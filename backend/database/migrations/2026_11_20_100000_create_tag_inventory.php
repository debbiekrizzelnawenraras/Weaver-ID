<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Panel directive (19 Nov 2026): an inventory of NFC tags.
 *
 * Tags now exist before they are linked. Staff receive a batch into one provincial
 * stock, send tags to an association, and the tag is linked to a product later.
 * nfc_tags.status: in_stock -> sent -> active (linked) -> revoked, or damaged (damaged or lost).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tag_batches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('supplier')->nullable();
            $table->date('received_on');
            $table->string('notes', 1000)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('nfc_tags', function (Blueprint $table) {
            $table->foreignId('tag_batch_id')->nullable()->after('uid')->constrained('tag_batches')->nullOnDelete();
            // Where the tag was sent. Kept on the tag so it stays known after linking.
            $table->foreignId('association_id')->nullable()->after('tag_batch_id')->constrained('associations')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
        });

        Schema::table('nfc_tags', function (Blueprint $table) {
            $table->string('status', 20)->default('in_stock')->change();
        });

        Schema::table('nfc_tag_events', function (Blueprint $table) {
            $table->unsignedBigInteger('association_id')->nullable();
            $table->string('note', 255)->nullable();
        });

        // Tags linked before the inventory existed go into one batch so every tag has a batch.
        if (DB::table('nfc_tags')->exists()) {
            $batchId = DB::table('tag_batches')->insertGetId([
                'code' => 'BEFORE-INVENTORY',
                'supplier' => null,
                'received_on' => DB::table('nfc_tags')->min('created_at') ?? now(),
                'notes' => 'Tags linked before the inventory was added.',
                'received_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('nfc_tags')->update([
                'tag_batch_id' => $batchId,
                'association_id' => DB::raw('(SELECT products.association_id FROM products WHERE products.id = nfc_tags.product_id)'),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('nfc_tags')->whereNotIn('status', ['active', 'revoked'])->delete();

        Schema::table('nfc_tag_events', function (Blueprint $table) {
            $table->dropColumn(['association_id', 'note']);
        });

        Schema::table('nfc_tags', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tag_batch_id');
            $table->dropConstrainedForeignId('association_id');
            $table->dropConstrainedForeignId('sent_by');
            $table->dropColumn('sent_at');
        });

        Schema::table('nfc_tags', function (Blueprint $table) {
            $table->enum('status', ['active', 'revoked'])->default('active')->change();
        });

        Schema::dropIfExists('tag_batches');
    }
};
