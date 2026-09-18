<script setup>
import { ref } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ link: Object, collections: Array });
const page = usePage();
const tab = ref('reader');

const editTitle = ref(props.link.title ?? '');
const editTags = ref((props.link.tags ?? []).map(t => t.name).join(', '));
const editCollection = ref(props.link.library_collection_id ?? '');
const quote = ref('');
const note = ref('');

function saveMeta() {
  router.patch(`/library/${props.link.id}`, {
    title: editTitle.value,
    library_collection_id: editCollection.value || null,
    tags: editTags.value,
  }, { preserveScroll: true });
}
function toggle(flag) {
  router.patch(`/library/${props.link.id}`, { [flag]: !props.link[flag] }, { preserveScroll: true });
}
function refresh() { router.post(`/library/${props.link.id}/refresh`, {}, { preserveScroll: true }); }
function preserve() { router.post(`/library/${props.link.id}/preserve`, {}, { preserveScroll: true }); }
function destroy() {
  if (!confirm('Delete this link permanently?')) return;
  router.delete(`/library/${props.link.id}`);
}
function addHighlight() {
  if (!quote.value.trim()) return;
  router.post(`/library/${props.link.id}/highlights`, { quote: quote.value, note: note.value || undefined }, {
    preserveScroll: true, onSuccess: () => { quote.value = ''; note.value = ''; },
  });
}
function delHighlight(id) { router.delete(`/library/${props.link.id}/highlights/${id}`, { preserveScroll: true }); }
function addShare() { router.post(`/library/${props.link.id}/shares`, {}, { preserveScroll: true }); }
function delShare(id) { router.delete(`/library/${props.link.id}/shares/${id}`, { preserveScroll: true }); }
function shareUrl(token) { return `${window.location.origin}/api/library/s/${token}`; }
</script>
<template>
  <AppLayout>
    <Link href="/library" class="text-xs text-gray-500 hover:underline">← Library</Link>

    <div class="flex items-start gap-3 mt-1">
      <img v-if="link.favicon_url" :src="link.favicon_url" class="w-8 h-8 rounded mt-1" alt="" @error="$event.target.style.display = 'none'" />
      <div class="flex-1 min-w-0">
        <h1 class="text-xl font-semibold leading-tight">{{ link.title }}</h1>
        <a :href="link.url" target="_blank" rel="noopener" class="text-xs text-primary-600 break-all hover:underline">{{ link.url }}</a>
        <p v-if="link.description" class="text-sm text-gray-500 mt-1">{{ link.description }}</p>
        <div class="flex flex-wrap items-center gap-1.5 mt-2 text-[11px]">
          <span class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-500">{{ link.host }}</span>
          <span v-if="link.reading_time_min" class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-500">📖 {{ link.reading_time_min }} min</span>
          <span class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-500">metadata: {{ link.metadata_status }}</span>
          <span class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-500">snapshot: {{ link.snapshot_status }}</span>
          <span v-if="link.is_favorite" class="text-yellow-500">★</span>
          <span v-if="link.is_pinned">📌</span>
          <span v-if="link.is_archived" class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-700">archived</span>
        </div>
      </div>
    </div>

    <div class="flex flex-wrap gap-1.5 mt-3">
      <button @click="toggle('is_favorite')" class="text-xs px-2.5 py-1 rounded-md border dark:border-gray-700">{{ link.is_favorite ? '★ Remove favorite' : '☆ Favorite' }}</button>
      <button @click="toggle('is_pinned')" class="text-xs px-2.5 py-1 rounded-md border dark:border-gray-700">{{ link.is_pinned ? '📌 Unpin' : 'Pin' }}</button>
      <button @click="toggle('is_read')" class="text-xs px-2.5 py-1 rounded-md border dark:border-gray-700">{{ link.is_read ? 'Mark as unread' : 'Mark as read' }}</button>
      <button @click="toggle('is_archived')" class="text-xs px-2.5 py-1 rounded-md border dark:border-gray-700">{{ link.is_archived ? 'Unarchive' : 'Archive' }}</button>
      <button @click="refresh" class="text-xs px-2.5 py-1 rounded-md border dark:border-gray-700">↻ Re-analyze</button>
      <button @click="preserve" class="text-xs px-2.5 py-1 rounded-md border dark:border-gray-700">💾 Preserve</button>
      <button @click="destroy" class="text-xs px-2.5 py-1 rounded-md border border-red-200 text-red-500">Delete</button>
    </div>
    <p v-if="page.props.errors?.preserve" class="text-xs text-red-500 mt-1">{{ page.props.errors.preserve }}</p>

    <div class="border rounded-lg p-3 mt-3 bg-white dark:bg-gray-900 dark:border-gray-800">
      <div class="grid sm:grid-cols-3 gap-2">
        <label class="text-xs text-gray-500">Title
          <input v-model="editTitle" class="mt-1 w-full border rounded-md px-2 py-1 text-sm dark:bg-gray-800 dark:border-gray-700" />
        </label>
        <label class="text-xs text-gray-500">Collection
          <select v-model="editCollection" class="mt-1 w-full border rounded-md px-2 py-1 text-sm dark:bg-gray-800 dark:border-gray-700">
            <option value="">No collection</option>
            <option v-for="c in collections ?? []" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </label>
        <label class="text-xs text-gray-500">Tags (comma separated)
          <input v-model="editTags" placeholder="paper, read later" class="mt-1 w-full border rounded-md px-2 py-1 text-sm dark:bg-gray-800 dark:border-gray-700" />
        </label>
      </div>
      <button @click="saveMeta" class="mt-2 text-xs px-3 py-1.5 rounded-md bg-primary-600 text-white">Save changes</button>
    </div>

    <div v-if="link.og_image_url" class="mt-3">
      <img :src="link.og_image_url" class="rounded-lg max-h-56 object-cover w-full" loading="lazy" alt="" @error="$event.target.style.display = 'none'" />
    </div>

    <div class="flex gap-1.5 mt-4 border-b dark:border-gray-800">
      <button v-for="t in ['reader', 'info', 'highlights', 'share']" :key="t" @click="tab = t"
        :class="['text-xs px-3 py-1.5 -mb-px border-b-2', tab === t ? 'border-primary-600 text-primary-600 font-medium' : 'border-transparent text-gray-500']">
        {{ { reader: 'Reader', info: 'Info', highlights: `Highlights (${(link.highlights ?? []).length})`, share: 'Share' }[t] }}
      </button>
    </div>

    <div v-if="tab === 'reader'" class="mt-3 border rounded-lg p-4 bg-white dark:bg-gray-900 dark:border-gray-800">
      <p v-if="link.metadata_status === 'pending'" class="text-sm text-gray-400">⏳ Analyzing content… reload in a few seconds. If it gets stuck, use “↻ Re-analyze”.</p>
      <p v-else-if="!link.readability_html" class="text-sm text-gray-400">No readable content (metadata: {{ link.metadata_status }}). Try “↻ Re-analyze” or “💾 Preserve”.</p>
      <div v-else class="prose prose-sm dark:prose-invert max-w-none prose-a:text-primary-600 prose-img:rounded-lg" v-html="link.readability_html"></div>
    </div>

    <div v-if="tab === 'info'" class="mt-3 border rounded-lg p-4 bg-white dark:bg-gray-900 dark:border-gray-800 text-sm space-y-1">
      <p><span class="text-gray-500">URL:</span> <a :href="link.url" target="_blank" class="text-primary-600 break-all">{{ link.url }}</a></p>
      <p><span class="text-gray-500">Canonical:</span> <span class="break-all">{{ link.canonical_url }}</span></p>
      <p><span class="text-gray-500">Host:</span> {{ link.host }}</p>
      <p><span class="text-gray-500">Created:</span> {{ link.created_at }}</p>
      <p><span class="text-gray-500">Snapshots:</span> {{ (link.snapshots ?? []).map(s => `${s.kind} (${s.size_bytes} B)`).join(', ') || '—' }}</p>
    </div>

    <div v-if="tab === 'highlights'" class="mt-3 border rounded-lg p-4 bg-white dark:bg-gray-900 dark:border-gray-800">
      <div class="flex flex-col sm:flex-row gap-2 mb-3">
        <input v-model="quote" placeholder="Quote…" class="flex-1 border rounded-md px-2 py-1 text-sm dark:bg-gray-800 dark:border-gray-700" />
        <input v-model="note" placeholder="Note (optional)" class="sm:w-56 border rounded-md px-2 py-1 text-sm dark:bg-gray-800 dark:border-gray-700" />
        <button @click="addHighlight" class="text-xs px-3 py-1 rounded-md bg-primary-600 text-white">Add</button>
      </div>
      <div v-for="h in link.highlights ?? []" :key="h.id" class="border-l-2 border-yellow-400 pl-2 py-1 mb-2">
        <p class="text-sm">“{{ h.quote }}”</p>
        <p v-if="h.note" class="text-xs text-gray-500">{{ h.note }}</p>
        <button @click="delHighlight(h.id)" class="text-[11px] text-red-400 hover:underline">delete</button>
      </div>
      <p v-if="!(link.highlights ?? []).length" class="text-xs text-gray-400">No highlights yet.</p>
    </div>

    <div v-if="tab === 'share'" class="mt-3 border rounded-lg p-4 bg-white dark:bg-gray-900 dark:border-gray-800">
      <button @click="addShare" class="text-xs px-3 py-1.5 rounded-md bg-primary-600 text-white">Create public link</button>
      <div v-for="s in link.shares ?? []" :key="s.id" class="flex items-center gap-2 mt-2 text-xs">
        <a :href="shareUrl(s.token)" target="_blank" class="text-primary-600 break-all hover:underline">{{ shareUrl(s.token) }}</a>
        <button @click="delShare(s.id)" class="text-red-400 hover:underline">revoke</button>
      </div>
      <p v-if="!(link.shares ?? []).length" class="text-xs text-gray-400 mt-2">No public links.</p>
    </div>
  </AppLayout>
</template>
