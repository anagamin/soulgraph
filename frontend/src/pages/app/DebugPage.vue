<script setup lang="ts">
import { onMounted, ref } from 'vue'
import api from '@/shared/api/client'

const aiLogs = ref<unknown[]>([])
const jobLogs = ref<unknown[]>([])
const projections = ref<unknown[]>([])
const rebuilding = ref(false)

async function load() {
  const [ai, jobs, proj] = await Promise.all([
    api.get('/debug/ai-logs'),
    api.get('/debug/jobs-logs'),
    api.get('/debug/projections'),
  ])
  aiLogs.value = ai.data.data ?? ai.data
  jobLogs.value = jobs.data.data ?? jobs.data
  projections.value = proj.data.data ?? proj.data
}

async function rebuild() {
  rebuilding.value = true
  await api.post('/debug/rebuild-graph')
  rebuilding.value = false
  await load()
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <h2 class="text-lg">Debug Dashboard</h2>
      <button class="rounded-lg bg-amber-600/80 px-4 py-2 text-sm hover:bg-amber-500" :disabled="rebuilding" @click="rebuild">
        {{ rebuilding ? 'Пересборка...' : 'Rebuild Graph' }}
      </button>
    </div>

    <section class="glass rounded-2xl p-4">
      <h3 class="text-sm text-violet-400">AI Logs</h3>
      <pre class="mt-2 max-h-48 overflow-auto text-xs text-zinc-400">{{ JSON.stringify(aiLogs.slice(0, 5), null, 2) }}</pre>
    </section>
    <section class="glass rounded-2xl p-4">
      <h3 class="text-sm text-amber-400">Jobs Logs</h3>
      <pre class="mt-2 max-h-48 overflow-auto text-xs text-zinc-400">{{ JSON.stringify(jobLogs.slice(0, 5), null, 2) }}</pre>
    </section>
    <section class="glass rounded-2xl p-4">
      <h3 class="text-sm text-sky-400">Projection Logs</h3>
      <pre class="mt-2 max-h-48 overflow-auto text-xs text-zinc-400">{{ JSON.stringify(projections.slice(0, 5), null, 2) }}</pre>
    </section>
  </div>
</template>
