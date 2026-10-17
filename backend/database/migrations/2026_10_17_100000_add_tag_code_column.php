<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfc_tags', function (Blueprint $table) {
            // True once the tag desk has written the server-made tag code onto the tag.
            // Only tags with a tag code are required to send one when they are scanned.
            $table->boolean('code_enabled')->default(false)->after('counter_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('nfc_tags', function (Blueprint $table) {
            $table->dropColumn('code_enabled');
        });
    }
};
