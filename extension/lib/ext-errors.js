/**
 * Centralized HTTP error classification for the extension surface.
 *
 * Lives in its own file so it can be exercised by Vitest without
 * loading the whole background script. Loaded via `manifest.json`
 * before `background/background.js`.
 *
 * Maps a server response (or a thrown network error) to a stable
 * `{ code, message }` pair. Codes are part of the contract between
 * the background and the popup/options pages.
 *
 *   network              - fetch threw / no response
 *   token_expired        - HTTP 401
 *   forbidden            - HTTP 403 (generic)
 *   quota_exceeded       - HTTP 403 with "quota" in the body message
 *   collection_disabled  - HTTP 403 with "disabled" / "collection" in body
 *   not_found            - HTTP 404
 *   precondition_failed  - HTTP 412 (ETag mismatch)
 *   rate_limited         - HTTP 429
 *   server_error         - HTTP 5xx
 *   http_error           - any other non-OK response
 */
(function (root) {
    function classify(status, body) {
        const message = (body && (body.message || body.error)) || '';
        if (status === 0) return { code: 'network', message: 'Network unavailable' };
        if (status === 401) return { code: 'token_expired', message: 'Session expired. Please sign in again.' };
        if (status === 403) {
            const lc = message.toLowerCase();
            if (lc.includes('quota')) return { code: 'quota_exceeded', message };
            if (lc.includes('disabled') || lc.includes('collection')) {
                return { code: 'collection_disabled', message: message || 'Collection disabled' };
            }
            return { code: 'forbidden', message: message || 'Forbidden' };
        }
        if (status === 404) return { code: 'not_found', message: message || 'Not found' };
        if (status === 412) return { code: 'precondition_failed', message: message || 'Precondition failed' };
        if (status === 429) return { code: 'rate_limited', message: message || 'Too many requests' };
        if (status >= 500) return { code: 'server_error', message: message || `Server error (${status})` };
        return { code: 'http_error', message: message || `HTTP ${status}` };
    }

    /**
     * Wrap `fetch` with classification. On network failure, throws an
     * `Error` whose `.code === 'network'`. On non-OK responses, throws
     * an `Error` whose `.code` is one of the codes above and whose
     * `.status` is the HTTP status.
     */
    async function extFetchJson(fetchFn, url, options = {}) {
        const { expectNoBody = false, ...fetchOptions } = options;
        let response;
        try {
            response = await fetchFn(url, fetchOptions);
        } catch (e) {
            const err = new Error('Network unavailable');
            err.code = 'network';
            err.cause = e;
            throw err;
        }
        if (!response.ok) {
            let body = null;
            try { body = await response.json(); } catch (_) { /* non-JSON */ }
            const info = classify(response.status, body);
            const err = new Error(info.message);
            err.code = info.code;
            err.status = response.status;
            throw err;
        }
        if (expectNoBody) return null;
        if (response.status === 204) return null;
        try { return await response.json(); } catch (_) { return null; }
    }

    const api = { classify, extFetchJson };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (root) root.ExtErrors = api;
})(typeof globalThis !== 'undefined' ? globalThis : this);
