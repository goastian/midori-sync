<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION midori_notify_sync_change() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    owner_id bigint;
BEGIN
    IF TG_TABLE_NAME = 'sync_streams' THEN
        owner_id := NEW.user_id;
    ELSE
        SELECT user_id INTO owner_id FROM sync_streams WHERE id = NEW.stream_id;
    END IF;
    IF owner_id IS NOT NULL THEN
        PERFORM pg_notify('midori_sync_change', owner_id::text);
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER midori_sync_change_notify
AFTER INSERT ON sync_changes
FOR EACH ROW EXECUTE FUNCTION midori_notify_sync_change();

CREATE TRIGGER midori_sync_stream_reset_notify
AFTER UPDATE OF generation ON sync_streams
FOR EACH ROW WHEN (OLD.generation IS DISTINCT FROM NEW.generation)
EXECUTE FUNCTION midori_notify_sync_change();

SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS midori_sync_stream_reset_notify ON sync_streams;
DROP TRIGGER IF EXISTS midori_sync_change_notify ON sync_changes;
DROP FUNCTION IF EXISTS midori_notify_sync_change();
SQL);
    }
};
