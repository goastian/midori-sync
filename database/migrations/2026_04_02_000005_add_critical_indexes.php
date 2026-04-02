<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bso', function (Blueprint $table) {
            $table->index(['user_id', 'collection_id', 'modified'], 'idx_bso_user_col_modified');
            $table->index(['user_id', 'collection_id', 'sortindex'], 'idx_bso_sortindex');
            $table->index(['expiry'], 'idx_bso_expiry_v2');
            $table->index(['user_id', 'collection_id', 'bso_id'], 'idx_bso_lookup');
        });

        Schema::table('hawk_tokens', function (Blueprint $table) {
            $table->index(['user_id', 'expires_at'], 'idx_hawk_user_expires');
        });

        Schema::table('user_collections', function (Blueprint $table) {
            $table->index(['user_id', 'collection_id'], 'idx_user_collection_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bso', function (Blueprint $table) {
            $table->dropIndex('idx_bso_user_col_modified');
            $table->dropIndex('idx_bso_sortindex');
            $table->dropIndex('idx_bso_expiry_v2');
            $table->dropIndex('idx_bso_lookup');
        });

        Schema::table('hawk_tokens', function (Blueprint $table) {
            $table->dropIndex('idx_hawk_user_expires');
        });

        Schema::table('user_collections', function (Blueprint $table) {
            $table->dropIndex('idx_user_collection_lookup');
        });
    }
};
