<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An association asks to be registered. Nothing exists in `associations` or `users`
        // until DTI-Mountain Province staff approve the application.
        Schema::create('association_applications', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();

            $table->string('association_name');
            $table->string('municipality', 100);

            // The person who will sign in for the association. The email is the login.
            $table->string('contact_name');
            $table->string('contact_phone', 20);
            $table->string('email')->index();
            // Hash of the password the applicant chose. Kept only while the application is pending.
            $table->string('password_hash')->nullable();

            $table->string('registration_details', 150)->nullable();

            // pending, approved, rejected
            $table->string('status', 20)->default('pending')->index();

            // Proof that the applicant accepted the privacy notice, and which version.
            $table->timestamp('consented_at');
            $table->string('notice_version', 20);

            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            // What approval created.
            $table->foreignId('association_id')->nullable()->constrained('associations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('association_applications');
    }
};
