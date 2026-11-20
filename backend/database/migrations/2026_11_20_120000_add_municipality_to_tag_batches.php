<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each Negosyo Center (municipality) receives its own tags: there is no provincial stock.
 * A batch now belongs to one municipality, and staff work only from their own municipality's stock.
 *
 * Existing batches get the municipality of the associations their tags went to, or else of
 * the staff member who received them. A batch whose tags went to several municipalities
 * (BEFORE-INVENTORY) is split into one batch per municipality.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tag_batches', function (Blueprint $table) {
            $table->string('municipality')->nullable()->after('code');
            $table->index('municipality');
        });

        foreach (DB::table('tag_batches')->get() as $batch) {
            $byMunicipality = DB::table('nfc_tags')
                ->join('associations', 'associations.id', '=', 'nfc_tags.association_id')
                ->where('nfc_tags.tag_batch_id', $batch->id)
                ->whereNotNull('associations.municipality')
                ->select('associations.municipality', 'nfc_tags.id')
                ->get()
                ->groupBy('municipality');

            $fallback = $batch->received_by
                ? DB::table('users')->where('id', $batch->received_by)->value('municipality')
                : null;

            $municipalities = $byMunicipality->keys()->values();
            $own = $municipalities->first() ?? $fallback;

            DB::table('tag_batches')->where('id', $batch->id)->update(['municipality' => $own]);

            foreach ($municipalities->slice(1) as $municipality) {
                $splitId = DB::table('tag_batches')->insertGetId([
                    'code' => mb_substr($batch->code.'-'.mb_strtoupper(preg_replace('/\s+/', '', $municipality)), 0, 50),
                    'municipality' => $municipality,
                    'supplier' => $batch->supplier,
                    'received_on' => $batch->received_on,
                    'notes' => $batch->notes,
                    'received_by' => $batch->received_by,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('nfc_tags')->whereIn('id', $byMunicipality[$municipality]->pluck('id'))->update(['tag_batch_id' => $splitId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('tag_batches', function (Blueprint $table) {
            $table->dropIndex(['municipality']);
            $table->dropColumn('municipality');
        });
    }
};
