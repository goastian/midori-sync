<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
const props = defineProps({ entitlement: Object, used: Object });
</script>
<template>
  <AppLayout>
    <h1 class="text-xl font-semibold">Billing</h1>
    <p class="text-sm text-gray-500 mt-1">Sync is free. Library is billed at payments.astian.org.</p>
    <div class="mt-4 border rounded-lg p-4 bg-white dark:bg-gray-900 max-w-lg">
      <p class="text-sm">Plan: <strong>{{ entitlement.plan }}</strong> ({{ entitlement.status }})</p>
      <p class="text-sm text-gray-500">Links used: {{ used.links }} / {{ entitlement.limits?.max_links === -1 ? '∞' : entitlement.limits?.max_links }}</p>
      <p class="text-xs text-gray-400 mt-1">Billing {{ entitlement.billing_enabled ? 'enabled' : 'disabled (self-host)' }} · cache {{ entitlement.cached ? 'yes' : 'no' }}</p>
      <a :href="entitlement.upgrade_url" class="inline-block mt-3 text-xs px-3 py-1.5 rounded-md bg-primary-600 text-white">Manage at payments.astian.org</a>
    </div>
  </AppLayout>
</template>
