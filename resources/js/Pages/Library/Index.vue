<script setup>
import { ref, computed } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ links: Object, collections: Array, tags: Array, entitlement: Object, filters: Object });
const page = usePage();

const q = ref(props.filters?.q ?? '');
const url = ref('');
const saveTags = ref('');
const showSave = ref(false);
const stateFilter = ref(
  props.filters?.is_favorite ? 'favorites' : props.filters?.is_pinned ? 'pinned' : props.filters?.is_archived ? 'archived' : 'all'
);

const limit = computed(() => props.entitlement?.limits?.max_links ?? null);
const used = computed(() => props.entitlement?.used?.links ?? props.links?.total ?? 0);

function currentParams(extra = {}) {
  const p = { q: q.value || undefined, ...extra };
  if (stateFilter.value === 'favorites') p.is_favorite = 1;
  if (stateFilter.value === 'pinned') p.is_pinned = 1;
  if (stateFilter.value === 'archived') p.is_archived = 1;
  return p;
}
function search() { router.get('/library', currentParams(), { preserveState: true, replace: true }); }
function filterByCollection(id) { router.get('/library', currentParams({ collection_id: id || undefined }), { preserveState: true }); }
function filterByTag(name) { router.get('/library', currentParams({ tag: name || undefined }), { preserveState: true }); }
function filterState(s) { stateFilter.value = s; search(); }

function save() {
  router.post('/library', { url: url.value, tags: saveTags.value || undefined }, {
    preserveScroll: true,
    onSuccess: () => { url.value = ''; saveTags.value = ''; showSave.value = false; },
  });
}
function toggle(link, flag) {
  router.patch(`/library/${link.id}`, { [flag]: !link[flag] }, { preserveScroll: true });
}
function remove(link) {
  if (!confirm(`Archivar "${link.title || link.url}"?`)) return;
  router.patch(`/library/${link.id}`, { is_archived: true }, { preserveScroll: true });
}
function collectionName(id) {
  return props.collections?.find(c => c.id === id)?.name ?? '—';
}
function statusBadge(l) {
  if (l.metadata_status === 'pending') return 'Analizando…';
  if (l.metadata_status === 'failed') return 'Sin metadata';
  if (l.snapshot_status === 'ready') return 'Preservado';
  return `${l.reading_time_min ?? '·'} min`;
}
</script>
<template>
  <AppLayout>
    <div class="flex items-center justify-between mb-4 gap-3">
      <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Library</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">Plan {{ entitlement?.plan }} · {{ used }} / {{ limit === -1 ? '∞' : limit }} links
          <a v-if="entitlement?.upgrade_url" :href="entitlement.upgrade_url" class="ml-2 text-primary-600 underline">Upgrade</a>
        </p>
      </div>
      <button @click="showSave = !showSave" class="text-xs px-3 py-1.5 rounded-md bg-primary-600 text-white hover:bg-primary-700">+ Save link</button>
    </div>

    <div v-if="showSave" class="mb-4 border rounded-lg p-3 bg-white dark:bg-gray-900">
      <div class="flex flex-col sm:flex-row gap-2">
        <input v-model="url" placeholder="https://…" class="flex-1 border rounded-md px-3 py-1.5 text-sm dark:bg-gray-800 dark:border-gray-700" />
        <input v-model="saveTags" placeholder="tags, coma, separado" class="sm:w-56 border rounded-md px-3 py-1.5 text-sm dark:bg-gray-800 dark:border-gray-700" />
        <button @click="save" class="text-xs px-4 py-1.5 rounded-md bg-primary-600 text-white">Guardar</button>
      </div>
      <p v-if="page.props.errors?.url" class="text-xs text-red-500 mt-1">{{ page.props.errors.url }}</p>
    </div>

    <div class="mb-4 flex gap-2">
      <input v-model="q" @keyup.enter="search" placeholder="Buscar por título, descripción, host o URL…" class="flex-1 border rounded-md px-3 py-1.5 text-sm dark:bg-gray-800 dark:border-gray-700" />
      <button @click="search" class="text-xs px-3 py-1.5 rounded-md border dark:border-gray-700">Buscar</button>
    </div>

    <div class="flex flex-wrap gap-1.5 mb-4">
      <button v-for="s in ['all', 'favorites', 'pinned', 'archived']" :key="s" @click="filterState(s)"
        :class="['text-xs px-2.5 py-1 rounded-full border', stateFilter === s ? 'bg-primary-600 text-white border-primary-600' : 'dark:border-gray-700 text-gray-500']">
        {{ { all: 'Todos', favorites: '★ Favoritos', pinned: '📌 Fijados', archived: 'Archivo' }[s] }}
      </button>
      <select :value="filters?.collection_id ?? ''" @change="filterByCollection($event.target.value || null)"
        class="text-xs px-2 py-1 rounded-full border dark:bg-gray-800 dark:border-gray-700">
        <option value="">Todas las colecciones</option>
        <option v-for="c in collections ?? []" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <select :value="filters?.tag ?? ''" @change="filterByTag($event.target.value || null)"
        class="text-xs px-2 py-1 rounded-full border dark:bg-gray-800 dark:border-gray-700">
        <option value="">Todos los tags</option>
        <option v-for="t in tags ?? []" :key="t.id" :value="t.name">#{{ t.name }}</option>
      </select>
    </div>

    <div class="grid gap-2">
      <div v-for="l in links?.data ?? []" :key="l.id"
        class="border rounded-lg p-3 bg-white dark:bg-gray-900 dark:border-gray-800 flex gap-3 hover:shadow">
        <img v-if="l.favicon_url" :src="l.favicon_url" class="w-5 h-5 mt-0.5 rounded shrink-0" loading="lazy" alt="" @error="$event.target.style.display = 'none'" />
        <div v-else class="w-5 h-5 mt-0.5 rounded shrink-0 bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-[10px]">🔗</div>
        <div class="flex-1 min-w-0">
          <Link :href="`/library/${l.id}`" class="text-sm font-medium hover:underline line-clamp-1">{{ l.title || l.url }}</Link>
          <p class="text-xs text-gray-500 truncate">{{ l.host }} · {{ collectionName(l.library_collection_id) }} · {{ statusBadge(l) }}</p>
          <p v-if="l.description" class="text-xs text-gray-400 line-clamp-1 mt-0.5">{{ l.description }}</p>
          <div class="flex flex-wrap gap-1 mt-1">
            <button v-for="t in l.tags ?? []" :key="t.id" @click="filterByTag(t.name)"
              class="text-[11px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300">#{{ t.name }}</button>
            <span v-if="l.is_favorite" class="text-[11px]">★</span>
            <span v-if="l.is_pinned" class="text-[11px]">📌</span>
          </div>
        </div>
        <div class="flex flex-col gap-1 shrink-0">
          <button @click="toggle(l, 'is_favorite')" :title="l.is_favorite ? 'Quitar favorito' : 'Favorito'"
            :class="['text-sm', l.is_favorite ? 'text-yellow-500' : 'text-gray-300 hover:text-yellow-400']">★</button>
          <button @click="toggle(l, 'is_pinned')" :title="l.is_pinned ? 'Desfijar' : 'Fijar'"
            :class="['text-sm', l.is_pinned ? 'text-primary-500' : 'text-gray-300 hover:text-primary-400']">📌</button>
          <button @click="remove(l)" title="Archivar" class="text-sm text-gray-300 hover:text-red-400">🗄</button>
        </div>
      </div>
      <p v-if="!(links?.data ?? []).length" class="text-sm text-gray-400 text-center py-8">Sin links con estos filtros. Guarda el primero con “+ Save link”.</p>
    </div>

    <div v-if="(links?.last_page ?? 1) > 1" class="flex gap-2 mt-4 text-xs">
      <Link v-if="links.prev_page_url" :href="links.prev_page_url" class="px-3 py-1 border rounded">← Anterior</Link>
      <span class="px-2 py-1 text-gray-500">Página {{ links.current_page }} / {{ links.last_page }}</span>
      <Link v-if="links.next_page_url" :href="links.next_page_url" class="px-3 py-1 border rounded">Siguiente →</Link>
    </div>
  </AppLayout>
</template>
