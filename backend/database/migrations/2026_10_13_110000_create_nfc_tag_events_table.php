<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only history of changes made to a tag after it was issued.
        Schema::create('nfc_tag_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nfc_tag_id')->constrained('nfc_tags')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);
            $table->unsignedBigInteger('from_product_id')->nullable();
            $table->unsignedBigInteger('to_product_id')->nullable();
            $table->timestamps();

            $table->index(['nfc_tag_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfc_tag_events');
    }
};
