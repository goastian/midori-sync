<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_save_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('revision')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('library_save_receipts', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
