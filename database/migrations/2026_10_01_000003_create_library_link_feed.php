<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_links', function (Blueprint $table) {
            $table->unsignedBigInteger('revision')->default(1);
        });
        Schema::create('library_link_streams', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->uuid('generation');
            $table->unsignedBigInteger('sequence')->default(0);
        });
        Schema::create('library_link_changes', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->uuid('link_id');
            $table->unsignedBigInteger('revision');
            $table->boolean('deleted');
            $table->jsonb('value')->nullable();
            $table->timestamp('created_at');
            $table->primary(['user_id', 'sequence']);
            $table->index(['user_id', 'link_id', 'sequence']);
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION midori_library_link_payload(_link library_links) RETURNS jsonb
LANGUAGE sql STABLE AS $$
    SELECT jsonb_build_object(
        'id', _link.id,
        'url', _link.url,
        'title', _link.title,
        'description', left(_link.description, 5000),
        'library_collection_id', _link.library_collection_id,
        'is_read', _link.is_read,
        'is_archived', _link.is_archived,
        'is_favorite', _link.is_favorite,
        'is_pinned', _link.is_pinned,
        'tags', (SELECT COALESCE(jsonb_agg(t.name ORDER BY t.name), '[]'::jsonb)
                 FROM library_link_tag lt JOIN library_tags t ON t.id = lt.library_tag_id
                 WHERE lt.library_link_id = _link.id),
        'created_at', to_char(_link.created_at, 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
        'updated_at', to_char(_link.updated_at, 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"')
    );
$$;
SQL);

        DB::statement(<<<'SQL'
INSERT INTO library_link_streams (user_id, generation, sequence)
SELECT user_id, gen_random_uuid(), count(*)
FROM library_links WHERE deleted_at IS NULL GROUP BY user_id
SQL);
        DB::statement(<<<'SQL'
INSERT INTO library_link_changes (user_id, sequence, link_id, revision, deleted, value, created_at)
SELECT l.user_id, ordered.sequence, l.id, l.revision, false, midori_library_link_payload(l), now()
FROM library_links l
JOIN (
    SELECT id, row_number() OVER (PARTITION BY user_id ORDER BY created_at, id) AS sequence
    FROM library_links WHERE deleted_at IS NULL
) ordered ON ordered.id = l.id
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION midori_library_link_revision() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.revision := 1;
    ELSE
        IF NEW.id IS DISTINCT FROM OLD.id OR NEW.user_id IS DISTINCT FROM OLD.user_id THEN
            RAISE EXCEPTION 'A Link ID and owner cannot change';
        END IF;
        NEW.revision := OLD.revision + 1;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER midori_library_link_revision_trigger
BEFORE INSERT OR UPDATE ON library_links
FOR EACH ROW EXECUTE FUNCTION midori_library_link_revision();

CREATE OR REPLACE FUNCTION midori_library_link_journal() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    owner_id bigint;
    record_id uuid;
    record_revision bigint;
    removed boolean;
    payload jsonb;
    next_sequence bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF NOT EXISTS (SELECT 1 FROM users WHERE id = OLD.user_id) THEN
            RETURN OLD;
        END IF;
        owner_id := OLD.user_id;
        record_id := OLD.id;
        record_revision := OLD.revision + 1;
        removed := true;
        payload := NULL;
    ELSE
        owner_id := NEW.user_id;
        record_id := NEW.id;
        record_revision := NEW.revision;
        removed := NEW.deleted_at IS NOT NULL;
        payload := CASE WHEN removed THEN NULL ELSE midori_library_link_payload(NEW) END;
    END IF;

    INSERT INTO library_link_streams (user_id, generation, sequence)
    VALUES (owner_id, gen_random_uuid(), 1)
    ON CONFLICT (user_id) DO UPDATE
        SET sequence = library_link_streams.sequence + 1
    RETURNING sequence INTO next_sequence;

    INSERT INTO library_link_changes (user_id, sequence, link_id, revision, deleted, value, created_at)
    VALUES (owner_id, next_sequence, record_id, record_revision, removed, payload, now());
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER midori_library_link_journal_trigger
AFTER INSERT OR UPDATE OR DELETE ON library_links
FOR EACH ROW EXECUTE FUNCTION midori_library_link_journal();

CREATE OR REPLACE FUNCTION midori_library_link_tag_changed() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    UPDATE library_links SET updated_at = clock_timestamp()
    WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.library_link_id ELSE NEW.library_link_id END
      AND deleted_at IS NULL;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER midori_library_link_tag_changed_trigger
AFTER INSERT OR DELETE ON library_link_tag
FOR EACH ROW EXECUTE FUNCTION midori_library_link_tag_changed();

CREATE OR REPLACE FUNCTION midori_library_tag_renamed() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.name IS DISTINCT FROM OLD.name THEN
        UPDATE library_links SET updated_at = clock_timestamp()
        WHERE id IN (SELECT library_link_id FROM library_link_tag WHERE library_tag_id = NEW.id)
          AND deleted_at IS NULL;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER midori_library_tag_renamed_trigger
AFTER UPDATE OF name ON library_tags
FOR EACH ROW EXECUTE FUNCTION midori_library_tag_renamed();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS midori_library_tag_renamed_trigger ON library_tags;
DROP TRIGGER IF EXISTS midori_library_link_tag_changed_trigger ON library_link_tag;
DROP TRIGGER IF EXISTS midori_library_link_journal_trigger ON library_links;
DROP TRIGGER IF EXISTS midori_library_link_revision_trigger ON library_links;
DROP FUNCTION IF EXISTS midori_library_tag_renamed();
DROP FUNCTION IF EXISTS midori_library_link_tag_changed();
DROP FUNCTION IF EXISTS midori_library_link_journal();
DROP FUNCTION IF EXISTS midori_library_link_revision();
DROP FUNCTION IF EXISTS midori_library_link_payload(library_links);
SQL);
        Schema::dropIfExists('library_link_changes');
        Schema::dropIfExists('library_link_streams');
        Schema::table('library_links', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
