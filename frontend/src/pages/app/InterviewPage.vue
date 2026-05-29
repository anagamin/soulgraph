<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/shared/api/client'

interface Session {
  id: string
  title: string
  session_type: string
  mode: string
  status: string
}

const router = useRouter()
const sessions = ref<Session[]>([])
const title = ref('')
const sessionType = ref('open_exploration')

const types = [
  { value: 'life_period', label: 'Жизненный период' },
  { value: 'relationship', label: 'Отношения' },
  { value: 'fear', label: 'Страх' },
  { value: 'identity', label: 'Идентичность' },
  { value: 'spirituality', label: 'Духовность' },
  { value: 'childhood', label: 'Детство' },
  { value: 'open_exploration', label: 'Свободное исследование' },
]

async function load() {
  const { data } = await api.get('/interview/sessions')
  sessions.value = data.data ?? data
}

async function create() {
  const { data } = await api.post('/interview/sessions', {
    title: title.value || 'Новая сессия',
    session_type: sessionType.value,
  })
  router.push(`/app/interview/${data.data?.id ?? data.id}`)
}

onMounted(load)
</script>

<template>
  <div class="grid gap-8 lg:grid-cols-2">
    <div class="glass rounded-2xl p-6">
      <h2 class="text-lg font-medium">Новая сессия</h2>
      <input v-model="title" placeholder="Название" class="mt-4 w-full rounded-lg border border-white/10 bg-black/30 px-4 py-2" />
      <select v-model="sessionType" class="mt-4 w-full rounded-lg border border-white/10 bg-black/30 px-4 py-2">
        <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
      </select>
      <button class="mt-4 rounded-lg bg-violet-600 px-4 py-2 hover:bg-violet-500" @click="create">Начать интервью</button>
    </div>

    <div class="glass rounded-2xl p-6">
      <h2 class="text-lg font-medium">Сессии</h2>
      <ul class="mt-4 space-y-2">
        <li
          v-for="s in sessions"
          :key="s.id"
          class="cursor-pointer rounded-lg border border-white/5 px-4 py-3 hover:bg-white/5"
          @click="router.push(`/app/interview/${s.id}`)"
        >
          <p class="font-medium">{{ s.title }}</p>
          <p class="text-xs text-zinc-500">{{ s.session_type }}</p>
        </li>
      </ul>
    </div>
  </div>
</template>
