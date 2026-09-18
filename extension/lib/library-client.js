/**
 * Midori Library client — Pocket/Linkwarden-style quick save.
 * Isolated from the sync engine: uses the same sync token but talks to /api/library.
 * Maps 402 plan_limit → upgrade banner pointing at payments.astian.org.
 */
class LibraryClient {
    constructor(baseUrl, getToken) {
        this.baseUrl = baseUrl.replace(/\/$/, '');
        this.getToken = getToken;
    }

    async _headers() {
        const token = await this.getToken();
        return {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`,
        };
    }

    _upgradeFrom(res, body) {
        if (res.status === 402 && body && body.code === 'plan_limit') {
            const err = new Error(body.error || 'Plan limit reached');
            err.code = 'plan_limit';
            err.upgradeUrl = body.upgrade_url;
            throw err;
        }
    }

    async saveQuick(url, { title = null, tags = [], collectionId = null } = {}) {
        const res = await fetch(`${this.baseUrl}/api/library/links`, {
            method: 'POST',
            headers: await this._headers(),
            body: JSON.stringify({
                url,
                title,
                tags,
                library_collection_id: collectionId,
            }),
        });
        const body = await res.json().catch(() => ({}));
        this._upgradeFrom(res, body);
        if (!res.ok) {
            const err = new Error(body.error || `HTTP ${res.status}`);
            err.code = body.code || 'http_error';
            throw err;
        }
        return body;
    }

    async preserve(linkId, kinds = ['html']) {
        const res = await fetch(`${this.baseUrl}/api/library/links/${linkId}/preserve`, {
            method: 'POST',
            headers: await this._headers(),
            body: JSON.stringify({ kinds }),
        });
        const body = await res.json().catch(() => ({}));
        this._upgradeFrom(res, body);
        if (!res.ok) {
            const err = new Error(body.error || `HTTP ${res.status}`);
            err.code = body.code || 'http_error';
            throw err;
        }
        return body;
    }

    async entitlement() {
        const res = await fetch(`${this.baseUrl}/api/library/entitlement`, {
            headers: await this._headers(),
        });
        return res.json();
    }
}

if (typeof globalThis !== 'undefined') {
    globalThis.LibraryClient = LibraryClient;
}
