<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_links', function (Blueprint $table) {
            $table->index(['user_id', 'created_at', 'id'], 'library_links_browse_index');
        });
    }

    public function down(): void
    {
        Schema::table('library_links', function (Blueprint $table) {
            $table->dropIndex('library_links_browse_index');
        });
    }
};
