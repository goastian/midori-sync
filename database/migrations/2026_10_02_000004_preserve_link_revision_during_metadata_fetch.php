<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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
        IF current_setting('midori.link_background', true) = 'on' THEN
            NEW.revision := OLD.revision;
        ELSE
            NEW.revision := OLD.revision + 1;
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
SQL);
    }

    public function down(): void
    {
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
SQL);
    }
};
