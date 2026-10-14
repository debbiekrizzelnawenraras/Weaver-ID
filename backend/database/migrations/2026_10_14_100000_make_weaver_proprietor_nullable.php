<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Data minimisation: the system no longer asks for a second personal name.
        // The column is kept (optional) so existing rows are not destroyed.
        Schema::table('weavers', function (Blueprint $table) {
            $table->string('proprietor')->nullable()->change();
        });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('weavers')->whereNull('proprietor')->update(['proprietor' => '']);

        Schema::table('weavers', function (Blueprint $table) {
            $table->string('proprietor')->nullable(false)->change();
        });
    }
};
