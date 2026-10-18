<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who added, changed or removed a product photo, and when (like nfc_tag_events for tags).
        Schema::create('product_photo_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 20); // added, changed, removed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_photo_events');
    }
};
