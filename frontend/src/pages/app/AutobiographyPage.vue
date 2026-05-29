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
}

const items = ref<Autobiography[]>([])
const title = ref('')
const style = ref('literary')
const scope = ref('full')
const selected = ref<Autobiography | null>(null)
const editedContent = ref('')

const styles = [
  { value: 'neutral', label: 'Нейтральный' },
  { value: 'literary', label: 'Литературный' },
  { value: 'philosophical', label: 'Философский' },
  { value: 'psychological', label: 'Психологический' },
  { value: 'documentary', label: 'Документальный' },
  { value: 'spiritual', label: 'Духовный' },
]

async function load() {
  const { data } = await api.get('/autobiographies')
  items.value = data.data ?? data
}

async function generate() {
  const { data } = await api.post('/autobiographies/generate', {
    title: title.value || 'Моя автобиография',
    style: style.value,
    scope: scope.value,
  })
  await load()
  selected.value = data
  editedContent.value = data.content ?? ''
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
      <button class="w-full rounded-lg bg-violet-600 py-2 hover:bg-violet-500" @click="generate">Сгенерировать</button>
      <ul class="space-y-2 border-t border-white/10 pt-4">
        <li
          v-for="item in items"
          :key="item.id"
          class="cursor-pointer rounded-lg px-3 py-2 text-sm hover:bg-white/5"
          :class="selected?.id === item.id ? 'bg-violet-500/20' : ''"
          @click="select(item)"
        >
          {{ item.title }} <span class="text-zinc-500">v{{ item.version }}</span>
          <span class="ml-2 text-xs" :class="item.status === 'completed' ? 'text-green-400' : 'text-amber-400'">{{ item.status }}</span>
        </li>
      </ul>
    </div>

    <div class="glass rounded-2xl p-6 lg:col-span-2">
      <div v-if="selected" class="flex h-full flex-col">
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
