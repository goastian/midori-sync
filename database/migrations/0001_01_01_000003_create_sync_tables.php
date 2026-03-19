<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for all Sync Storage tables.
     */
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();
        });

        // Seed default collections used by Firefox Sync 1.5
        $defaults = [
            'bookmarks', 'history', 'forms', 'prefs', 'tabs',
            'passwords', 'addons', 'addresses', 'creditcards',
            'extension-storage', 'clients', 'meta', 'crypto',
        ];
        foreach ($defaults as $name) {
            \Illuminate\Support\Facades\DB::table('collections')->insert(['name' => $name]);
        }

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_id');
            $table->string('name')->nullable();
            $table->string('type', 50)->nullable(); // desktop, mobile, tablet
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_id']);
        });

        Schema::create('hawk_tokens', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('hawk_key', 128);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index('expires_at');
        });

        Schema::create('user_collections', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->double('modified');
            $table->primary(['user_id', 'collection_id']);
        });

        Schema::create('bso', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->string('bso_id', 64);
            $table->integer('sortindex')->nullable();
            $table->text('payload')->nullable();
            $table->integer('payload_size')->default(0);
            $table->double('modified');
            $table->integer('ttl')->nullable();
            $table->timestamp('expiry')->nullable();
            $table->primary(['user_id', 'collection_id', 'bso_id']);
            $table->index(['user_id', 'collection_id', 'modified'], 'idx_bso_modified');
            $table->index('expiry', 'idx_bso_expiry');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bso');
        Schema::dropIfExists('user_collections');
        Schema::dropIfExists('hawk_tokens');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('collections');
    }
};
