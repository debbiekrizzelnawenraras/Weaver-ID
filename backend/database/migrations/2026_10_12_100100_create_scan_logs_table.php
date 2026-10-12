<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_logs', function (Blueprint $table) {
            $table->id();

            // The scanned UID is kept even when it matches no registered tag.
            $table->string('uid', 20)->index();

            $table->foreignId('nfc_tag_id')
                ->nullable()
                ->constrained('nfc_tags')
                ->nullOnDelete();

            $table->enum('result', [
                'authenticated',
                'unregistered',
                'suspicious',
            ]);

            $table->string('device_id', 100)->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['nfc_tag_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_logs');
    }
};
