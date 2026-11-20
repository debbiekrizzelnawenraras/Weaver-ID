<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wrong-center check (team decision, 20 Nov 2026).
 *
 * tag_assignments: the delivery list the administrator (DTI provincial) makes before tags ship:
 * which UID goes to which Negosyo Center. received_at is filled when that center scans it in.
 *
 * tag_alerts: a center tried to receive a UID that is assigned to another center. The receive is
 * refused, and the alert stays open on the admin dashboard until the admin moves the tag to the
 * center that has it, or dismisses the alert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tag_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 20)->unique();
            $table->string('municipality')->index();
            $table->string('delivery_ref', 50)->nullable()->index();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tag_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 20)->index();
            $table->string('assigned_municipality');
            $table->string('attempted_municipality');
            $table->foreignId('attempted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('open')->index(); // open | moved | dismissed
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_alerts');
        Schema::dropIfExists('tag_assignments');
    }
};
