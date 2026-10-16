<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfc_tags', function (Blueprint $table) {
            // True once the tag desk has set the write password and turned on the tag's read counter.
            $table->boolean('counter_enabled')->default(false)->after('status');
            // Highest counter value the server has seen for this tag (24-bit counter on NTAG21x).
            $table->unsignedInteger('last_counter')->nullable()->after('counter_enabled');
            $table->timestamp('secured_at')->nullable()->after('last_counter');
        });

        Schema::table('scan_logs', function (Blueprint $table) {
            $table->unsignedInteger('counter')->nullable()->after('ip_address');
            // Why a scan was flagged, for the audit view. Never shown to the public.
            $table->string('flag_reason', 50)->nullable()->after('counter');
        });
    }

    public function down(): void
    {
        Schema::table('scan_logs', function (Blueprint $table) {
            $table->dropColumn(['counter', 'flag_reason']);
        });

        Schema::table('nfc_tags', function (Blueprint $table) {
            $table->dropColumn(['counter_enabled', 'last_counter', 'secured_at']);
        });
    }
};
