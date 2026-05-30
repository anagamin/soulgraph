<script setup lang="ts">
import { onMounted, ref } from 'vue'
import api from '@/shared/api/client'

interface Autobiography {
  id: string
  title: string
  style: string
  content: string
  status: string
  version: number
  scope_params?: {
    generation_error?: string
    generation_step?: string
    generation_step_at?: string
    generation_log?: { at: string; message: string }[]
  }
}

const items = ref<Autobiography[]>([])
const title = ref('')
const style = ref('literary')
const scope = ref('full')
const selected = ref<Autobiography | null>(null)
const editedContent = ref('')
const generating = ref(false)

const styles = [
  { value: 'neutral', label: 'Нейтральный' },
  { value: 'literary', label: 'Литературный' },
  { value: 'philosophical', label: 'Философский' },
  { value: 'psychological', label: 'Психологический' },
  { value: 'documentary', label: 'Документальный' },
  { value: 'spiritual', label: 'Духовный' },
]

function statusClass(status: string) {
  if (status === 'completed') return 'text-green-400'
  if (status === 'failed') return 'text-red-400'
  if (status === 'processing') return 'text-blue-400'
  return 'text-amber-400'
}

function statusLabel(status: string) {
  const labels: Record<string, string> = {
    pending: 'ожидание',
    processing: 'генерация…',
    completed: 'готово',
    failed: 'ошибка',
  }
  return labels[status] ?? status
}

async function load() {
  const { data } = await api.get('/autobiographies')
  items.value = data.data ?? data
}

async function waitForCompletion(id: string, maxAttempts = 900) {
  for (let i = 0; i < maxAttempts; i++) {
    const { data } = await api.get(`/autobiographies/${id}`)
    selected.value = data
    editedContent.value = data.content ?? ''

    const idx = items.value.findIndex((item) => item.id === id)
    if (idx >= 0) {
      items.value[idx] = data
    }

    if (data.status === 'completed' || data.status === 'failed') {
      return
    }

    await new Promise((resolve) => setTimeout(resolve, 2000))
  }
}

async function generate() {
  if (generating.value) return
  generating.value = true
  try {
    const { data } = await api.post('/autobiographies/generate', {
      title: title.value || 'Моя автобиография',
      style: style.value,
      scope: scope.value,
    })
    selected.value = data
    editedContent.value = data.content ?? ''
    await load()

    if (data.status === 'pending' || data.status === 'processing') {
      await waitForCompletion(data.id)
      await load()
    }
  } finally {
    generating.value = false
  }
}

async function saveVersion() {
  if (!selected.value) return
  await api.post(`/autobiographies/${selected.value.id}/versions`, {
    content: editedContent.value,
    style: style.value,
  })
  await load()
}

async function exportMd() {
  if (!selected.value) return
  window.open(`/api/v1/autobiographies/${selected.value.id}/export.md`, '_blank')
}

function select(item: Autobiography) {
  selected.value = item
  editedContent.value = item.content
}

onMounted(load)
</script>

<template>
  <div class="grid gap-6 lg:grid-cols-3">
    <div class="glass space-y-4 rounded-2xl p-6">
      <h2 class="font-medium">Генерация</h2>
      <input v-model="title" placeholder="Название" class="w-full rounded-lg border border-white/10 bg-black/30 px-4 py-2" />
      <select v-model="style" class="w-full rounded-lg border border-white/10 bg-black/30 px-4 py-2">
        <option v-for="s in styles" :key="s.value" :value="s.value">{{ s.label }}</option>
      </select>
      <button
        class="w-full rounded-lg bg-violet-600 py-2 hover:bg-violet-500 disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="generating"
        @click="generate"
      >
        {{ generating ? 'Генерация…' : 'Сгенерировать' }}
      </button>
      <ul class="space-y-2 border-t border-white/10 pt-4">
        <li
          v-for="item in items"
          :key="item.id"
          class="cursor-pointer rounded-lg px-3 py-2 text-sm hover:bg-white/5"
          :class="selected?.id === item.id ? 'bg-violet-500/20' : ''"
          @click="select(item)"
        >
          {{ item.title }} <span class="text-zinc-500">v{{ item.version }}</span>
          <span class="ml-2 text-xs" :class="statusClass(item.status)">{{ statusLabel(item.status) }}</span>
        </li>
      </ul>
    </div>

    <div class="glass rounded-2xl p-6 lg:col-span-2">
      <div v-if="selected" class="flex h-full flex-col">
        <p v-if="selected.status === 'failed'" class="mb-4 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm text-red-300">
          <span v-if="selected.scope_params?.generation_error">{{ selected.scope_params.generation_error }}</span>
          <span v-else>Не удалось сгенерировать автобиографию. Проверьте GPTUNNEL_API_KEY и перезапустите очередь: php artisan queue:work --timeout=360</span>
        </p>
        <p v-else-if="generating || selected.status === 'pending' || selected.status === 'processing'" class="mb-4 text-sm text-zinc-400">
          Генерация может занять 10–20 минут (несколько запросов к AI)…
          <span v-if="selected.scope_params?.generation_step" class="block text-xs text-zinc-500">
            Шаг: {{ selected.scope_params.generation_step }}
            <template v-if="selected.scope_params.generation_step_at">
              · с {{ new Date(selected.scope_params.generation_step_at).toLocaleTimeString() }}
            </template>
          </span>
          <span
            v-for="(entry, i) in selected.scope_params?.generation_log?.slice(-3) ?? []"
            :key="i"
            class="block text-xs text-zinc-600"
          >
            {{ entry.message }}
          </span>
        </p>
        <div class="mb-4 flex gap-2">
          <button class="rounded-lg border border-white/10 px-3 py-1 text-sm hover:bg-white/5" @click="saveVersion">Сохранить версию</button>
          <button class="rounded-lg border border-white/10 px-3 py-1 text-sm hover:bg-white/5" @click="exportMd">Экспорт MD</button>
        </div>
        <textarea v-model="editedContent" class="min-h-[400px] flex-1 resize-none rounded-lg border border-white/10 bg-black/30 p-4 font-mono text-sm" />
      </div>
      <p v-else class="text-zinc-500">Выберите или создайте автобиографию</p>
    </div>
  </div>
</template>
