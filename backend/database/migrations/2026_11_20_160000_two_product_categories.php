<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two product categories (PIWPC forum, 12 Nov 2026; team decision, 20 Nov 2026).
 *
 * products.category was free text. From now on it is one of:
 *  - Indigenous Traditional Garment: a traditional garment with a prescribed design, used in rituals.
 *  - Traditional Woven Fabric: cloth woven by hand on a backstrap or floor loom, and items made mainly from it.
 *
 * Existing products are sorted as the team approved: a product whose old category says garment, attire
 * or clothing, or whose name is a tapis, wanes or bahag, becomes a Garment; everything else becomes Fabric.
 * The old text is kept in category_before, so a rollback restores it exactly.
 */
return new class extends Migration
{
    private const GARMENT = 'Indigenous Traditional Garment';

    private const FABRIC = 'Traditional Woven Fabric';

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('category_before')->nullable()->after('category');
        });

        foreach (DB::table('products')->get(['id', 'title', 'category']) as $product) {
            DB::table('products')->where('id', $product->id)->update([
                'category_before' => $product->category,
                'category' => $this->sort((string) $product->category, (string) $product->title),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('products')->whereNotNull('category_before')->update(['category' => DB::raw('category_before')]);

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('category_before');
        });
    }

    private function sort(string $category, string $title): string
    {
        $category = mb_strtolower($category);
        $title = mb_strtolower($title);

        if (in_array($category, [mb_strtolower(self::GARMENT), mb_strtolower(self::FABRIC)], true)) {
            return $category === mb_strtolower(self::GARMENT) ? self::GARMENT : self::FABRIC;
        }

        if (preg_match('/garment|attire|clothing/', $category)) {
            return self::GARMENT;
        }

        // A blanket or runner with a garment's pattern name (for example "Pinagpagan Pattern Blanket") stays Fabric.
        if (preg_match('/\b(tapis|wanes|bahag)\b/', $title) && ! preg_match('/blanket|runner|bag|scarf/', $title)) {
            return self::GARMENT;
        }

        return self::FABRIC;
    }
};
