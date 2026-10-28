<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set when the applicant typed the code that was emailed to the address. Empty when the
        // application was sent while email codes were switched off (WEAVER_EMAIL_CODES=off).
        Schema::table('association_applications', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('association_applications', function (Blueprint $table) {
            $table->dropColumn('email_verified_at');
        });
    }
};
