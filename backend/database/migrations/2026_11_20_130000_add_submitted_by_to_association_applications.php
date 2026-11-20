<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff no longer create associations directly. "Add association" now files an application
 * on the association's behalf, which the administrator approves like any other.
 * submitted_by is the staff member who filed it (null when the association applied itself).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association_applications', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable()->after('notice_version')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('association_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
        });
    }
};
