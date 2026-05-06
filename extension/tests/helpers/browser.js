/**
 * Minimal `browser.*` API mock for unit-testing the extension under
 * Vitest/Node. Only the surface used by the modules under test is
 * stubbed — extending it is fine, but keep the contract honest.
 */

export function createBrowserMock(initial = {}) {
    const storage = { ...(initial.storage || {}) };
    const bookmarks = initial.bookmarks || [];
    const history = initial.history || [];
    const tabs = initial.tabs || [];
    const alarms = new Map();

    const browser = {
        storage: {
            local: {
                async get(keys) {
                    if (!keys) return { ...storage };
                    if (typeof keys === 'string') {
                        return storage[keys] !== undefined ? { [keys]: storage[keys] } : {};
                    }
                    if (Array.isArray(keys)) {
                        const out = {};
                        for (const k of keys) if (storage[k] !== undefined) out[k] = storage[k];
                        return out;
                    }
                    if (typeof keys === 'object') {
                        const out = {};
                        for (const k of Object.keys(keys)) {
                            out[k] = storage[k] !== undefined ? storage[k] : keys[k];
                        }
                        return out;
                    }
                    return {};
                },
                async set(items) {
                    Object.assign(storage, items);
                },
                async remove(keys) {
                    const arr = Array.isArray(keys) ? keys : [keys];
                    for (const k of arr) delete storage[k];
                },
                async clear() {
                    for (const k of Object.keys(storage)) delete storage[k];
                },
                _peek: () => ({ ...storage }),
            },
        },

        bookmarks: {
            async getTree() {
                return [{ id: 'root________', children: bookmarks }];
            },
            async get(id) {
                const found = bookmarks.find((b) => b.id === id);
                return found ? [found] : [];
            },
        },

        history: {
            async search({ startTime = 0, maxResults = 1000 } = {}) {
                return history
                    .filter((h) => h.lastVisitTime >= startTime)
                    .slice(0, maxResults);
            },
        },

        tabs: {
            async query() {
                return tabs.slice();
            },
        },

        alarms: {
            async create(name, info) {
                alarms.set(name, info);
            },
            async clear(name) {
                return alarms.delete(name);
            },
            async clearAll() {
                alarms.clear();
            },
            onAlarm: { addListener: () => {} },
            _peek: () => new Map(alarms),
        },

        runtime: {
            getURL: (path) => `moz-extension://test/${path}`,
            onStartup: { addListener: () => {} },
            onInstalled: { addListener: () => {} },
            onMessage: { addListener: () => {} },
        },

        browserAction: {
            setBadgeText: () => {},
            setBadgeBackgroundColor: () => {},
        },
    };

    return { browser, storage, alarms };
}

/**
 * Build a minimal `Response`-like object for fetch-mock returns.
 */
export function jsonResponse(body, { status = 200, ok } = {}) {
    return {
        ok: ok !== undefined ? ok : status >= 200 && status < 300,
        status,
        async json() {
            return body;
        },
    };
}
