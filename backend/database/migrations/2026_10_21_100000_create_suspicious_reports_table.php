<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reports a consumer sends from the app after a tag was flagged as suspicious.
        // DTI-Mountain Province reviews them; a report never changes the tag by itself.
        Schema::create('suspicious_reports', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();

            $table->string('uid', 20)->index();
            $table->foreignId('nfc_tag_id')->nullable()->constrained('nfc_tags')->nullOnDelete();
            // The flagged scan this report is about, and why it was flagged (copied by the server, never sent by the app).
            $table->foreignId('scan_log_id')->nullable()->constrained('scan_logs')->nullOnDelete();
            $table->string('flag_reason', 50)->nullable();

            // Device id (or IP address when none is sent), used to avoid duplicate reports.
            $table->string('reporter_key', 100);
            $table->string('app_version', 20)->nullable();

            // What the person typed. Everything except the location is optional.
            $table->string('location', 150);
            $table->date('seen_on')->nullable();
            $table->text('note')->nullable();
            $table->string('contact', 120)->nullable();

            // new, under_review, verified, dismissed, referred
            $table->string('status', 20)->default('new')->index();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['uid', 'reporter_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspicious_reports');
    }
};
