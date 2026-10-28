<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One short-lived 6-digit code per email address and purpose. Only a scrambled copy of the
        // code is stored; the plain code exists in the email and nowhere else.
        Schema::create('email_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            // application = confirm the email of an association application; password_reset = choose a new password
            $table->string('purpose', 20);
            $table->string('code_hash', 64);
            // Wrong tries so far. After the limit the code is dead and a new one must be asked for.
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            // When the code was last emailed, so another one cannot be asked for within a minute.
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['email', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_codes');
    }
};
