<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weavers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('association_id')
                ->constrained('associations')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('proprietor');
            $table->string('municipality');
            $table->enum('status', [
                'active',
                'inactive',
            ])->default('active');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weavers');
    }
};