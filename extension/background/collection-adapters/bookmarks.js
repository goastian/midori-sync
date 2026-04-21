/**
 * Bookmarks Collection Adapter
 * Uses browser.bookmarks API to read/write bookmarks.
 */
class BookmarksAdapter {
    async getAll() {
        const tree = await browser.bookmarks.getTree();
        return this._flattenTree(tree[0]);
    }

    async getChangesSince(timestamp) {
        // bookmarks API doesn't support change tracking; return all
        return this.getAll();
    }

    async applyRemote(records) {
        for (const record of records) {
            if (record.deleted) {
                try {
                    await browser.bookmarks.remove(record.id);
                } catch { /* already removed */ }
                continue;
            }

            const data = record.data;
            if (!data) continue;

            try {
                const existing = await browser.bookmarks.get(data.id).catch(() => null);
                if (existing && existing.length > 0) {
                    await browser.bookmarks.update(data.id, {
                        title: data.title,
                        url: data.url,
                    });
                } else {
                    await browser.bookmarks.create({
                        parentId: data.parentId,
                        title: data.title,
                        url: data.url,
                        index: data.index,
                    });
                }
            } catch (err) {
                console.warn('[BookmarksAdapter] Failed to apply:', data.id, err);
            }
        }
    }

    async toSyncRecord(item) {
        return {
            id: item.id,
            data: {
                id: item.id,
                parentId: item.parentId,
                title: item.title,
                url: item.url || null,
                type: item.type || (item.url ? 'bookmark' : 'folder'),
                index: item.index,
                dateAdded: item.dateAdded,
            },
        };
    }

    async fromSyncRecord(record) {
        return record.data;
    }

    _flattenTree(node, result = []) {
        if (node.url || node.type === 'bookmark') {
            result.push(node);
        }
        if (node.children) {
            // Include folders too
            if (node.id !== 'root________') {
                result.push(node);
            }
            for (const child of node.children) {
                this._flattenTree(child, result);
            }
        }
        return result;
    }
}

if (typeof globalThis !== 'undefined') {
    globalThis.BookmarksAdapter = BookmarksAdapter;
}
