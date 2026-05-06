import { describe, it, expect, beforeAll } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Guardrail: enforce that every collection name appearing in any of the
 * three sources of truth (CollectionSeeder, COLLECTION_INDEX, adapter
 * filenames) is acknowledged by the others.
 *
 * The rules encoded here:
 *   1. Every name created by the backend seeder MUST exist in the
 *      extension's COLLECTION_INDEX (canonical or alias). Otherwise the
 *      extension cannot derive a sub-key for that collection.
 *   2. Every canonical name in COLLECTION_INDEX (i.e. not a documented
 *      backward-compat alias) MUST appear in the seeder.
 *   3. Every adapter file under extension/background/collection-adapters/
 *      MUST resolve to a name present in COLLECTION_INDEX.
 *
 * Aliases live only in COLLECTION_INDEX. They MUST share an index with
 * a canonical sibling and MUST NOT exist in the seeder (so the database
 * never grows duplicate canonical/alias rows again).
 */

const ROOT = resolve(import.meta.dirname, '..');
const SEEDER_PATH = resolve(ROOT, 'database/seeders/CollectionSeeder.php');
const CRYPTO_PATH = resolve(ROOT, 'extension/lib/midori-sync-crypto.js');
const ADAPTERS_DIR = resolve(ROOT, 'extension/background/collection-adapters');

// Aliases are intentional backward-compat shims, not separate collections.
// Keep this list small and documented.
const ALIASES = new Set(['open-tabs']);

// Collections orchestrated by the engine but without a dedicated
// adapter file (they're driven inline from background.js or the UI).
const ADAPTERLESS = new Set(['passwords', 'devices']);

let seederNames;
let cryptoIndex;
let adapterNames;

beforeAll(() => {
    const seederSrc = readFileSync(SEEDER_PATH, 'utf-8');
    seederNames = new Set(
        Array.from(seederSrc.matchAll(/'name'\s*=>\s*'([^']+)'/g)).map(m => m[1])
    );

    const cryptoSrc = readFileSync(CRYPTO_PATH, 'utf-8');
    const block = cryptoSrc.match(/COLLECTION_INDEX\s*=\s*\{([^}]+)\}/);
    expect(block, 'COLLECTION_INDEX literal not found').toBeTruthy();
    cryptoIndex = {};
    for (const m of block[1].matchAll(/'([^']+)'\s*:\s*(\d+)/g)) {
        cryptoIndex[m[1]] = Number(m[2]);
    }

    adapterNames = new Set(
        readdirSync(ADAPTERS_DIR)
            .filter(f => f.endsWith('.js'))
            .map(f => f.replace(/\.js$/, ''))
    );
});

describe('collection scope guardrail', () => {
    it('every seeder collection has a KDF index in COLLECTION_INDEX', () => {
        for (const name of seederNames) {
            expect(
                cryptoIndex[name],
                `seeder declares "${name}" but extension COLLECTION_INDEX has no entry`
            ).toBeDefined();
        }
    });

    it('every canonical COLLECTION_INDEX entry is created by the seeder', () => {
        for (const name of Object.keys(cryptoIndex)) {
            if (ALIASES.has(name)) continue;
            expect(
                seederNames.has(name),
                `COLLECTION_INDEX has canonical "${name}" but seeder does not create it`
            ).toBe(true);
        }
    });

    it('aliases share an index with a canonical sibling and are NOT in the seeder', () => {
        for (const alias of ALIASES) {
            expect(cryptoIndex[alias], `alias "${alias}" missing from COLLECTION_INDEX`).toBeDefined();
            const idx = cryptoIndex[alias];
            const canonical = Object.entries(cryptoIndex)
                .find(([n, i]) => n !== alias && i === idx && !ALIASES.has(n));
            expect(canonical, `alias "${alias}" has no canonical sibling at index ${idx}`).toBeTruthy();
            expect(
                seederNames.has(alias),
                `alias "${alias}" must NOT appear in the seeder (would create a duplicate row)`
            ).toBe(false);
        }
    });

    it('every adapter file maps to a COLLECTION_INDEX entry', () => {
        for (const adapter of adapterNames) {
            expect(
                cryptoIndex[adapter],
                `adapter "${adapter}.js" has no entry in COLLECTION_INDEX`
            ).toBeDefined();
        }
    });

    it('every canonical collection has either an adapter file or is explicitly adapterless', () => {
        for (const name of Object.keys(cryptoIndex)) {
            if (ALIASES.has(name)) continue;
            const hasAdapter = adapterNames.has(name);
            const exempt = ADAPTERLESS.has(name);
            expect(
                hasAdapter || exempt,
                `canonical collection "${name}" has no adapter and is not in the ADAPTERLESS allowlist`
            ).toBe(true);
        }
    });
});
