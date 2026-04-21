<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_collections', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->decimal('last_modified', 16, 6)->default(0);
            $table->unsignedInteger('record_count')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0);

            $table->primary(['user_id', 'collection_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_collections');
    }
};
