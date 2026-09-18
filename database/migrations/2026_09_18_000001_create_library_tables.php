<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cache local de entitlements: payments.astian.org es la fuente de verdad.
        Schema::create('billing_cache', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('plan')->default('free');
            $table->string('status')->default('active');
            $table->json('limits')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type');
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('library_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('library_collections')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->string('public_slug')->nullable()->unique();
            $table->timestamps();

            $table->index(['user_id', 'parent_id']);
        });

        Schema::create('library_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 16)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('library_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('library_collection_id')->nullable()->constrained('library_collections')->nullOnDelete();
            $table->text('url');
            $table->text('canonical_url')->nullable();
            $table->string('host', 255)->nullable();
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->text('favicon_url')->nullable();
            $table->text('og_image_url')->nullable();
            $table->text('readability_html')->nullable();
            $table->integer('reading_time_min')->nullable();
            $table->boolean('is_read')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->string('metadata_status')->default('pending');
            $table->string('snapshot_status')->default('none');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'host']);
            $table->index(['user_id', 'is_archived']);
            $table->index(['user_id', 'is_favorite']);
        });

        Schema::create('library_link_tag', function (Blueprint $table) {
            $table->foreignUuid('library_link_id')->constrained('library_links')->cascadeOnDelete();
            $table->foreignId('library_tag_id')->constrained('library_tags')->cascadeOnDelete();
            $table->primary(['library_link_id', 'library_tag_id']);
        });

        Schema::create('library_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('library_link_id')->constrained('library_links')->cascadeOnDelete();
            $table->string('kind'); // html|screenshot|pdf
            $table->string('storage_path');
            $table->string('mime')->nullable();
            $table->bigInteger('size_bytes')->default(0);
            $table->timestamps();

            $table->index('library_link_id');
        });

        Schema::create('library_highlights', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('library_link_id')->constrained('library_links')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('quote');
            $table->text('note')->nullable();
            $table->string('color', 16)->default('yellow');
            $table->json('anchor')->nullable();
            $table->timestamps();

            $table->index('library_link_id');
        });

        Schema::create('library_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('library_link_id')->constrained('library_links')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('library_import_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('source'); // netscape|pocket|csv
            $table->string('status')->default('pending');
            $table->integer('total')->default(0);
            $table->integer('processed')->default(0);
            $table->json('errors')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_import_jobs');
        Schema::dropIfExists('library_shares');
        Schema::dropIfExists('library_highlights');
        Schema::dropIfExists('library_snapshots');
        Schema::dropIfExists('library_link_tag');
        Schema::dropIfExists('library_links');
        Schema::dropIfExists('library_tags');
        Schema::dropIfExists('library_collections');
        Schema::dropIfExists('billing_events');
        Schema::dropIfExists('billing_cache');
    }
};
