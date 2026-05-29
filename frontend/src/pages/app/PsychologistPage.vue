<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { marked } from 'marked'
import api from '@/shared/api/client'

interface Message {
  role: string
  content: string
}

const sessionId = ref<string | null>(null)
const messages = ref<Message[]>([])
const input = ref('')
const loading = ref(false)

async function ensureSession() {
  const { data } = await api.get('/psychologist/sessions')
  const list = data.data ?? data
  if (list.length) {
    sessionId.value = list[0].id
  } else {
    const created = await api.post('/psychologist/sessions', {})
    sessionId.value = created.data.id
  }
}

async function send() {
  if (!sessionId.value || !input.value.trim()) return
  const content = input.value
  input.value = ''
  messages.value.push({ role: 'user', content })
  loading.value = true
  try {
    const { data } = await api.post(`/psychologist/sessions/${sessionId.value}/messages`, { content })
    messages.value.push(data.assistant_message)
  } finally {
    loading.value = false
  }
}

function renderMd(text: string) {
  return marked(text, { async: false }) as string
}

onMounted(ensureSession)
</script>

<template>
  <div class="glass flex h-[calc(100vh-10rem)] flex-col rounded-2xl p-6">
    <p class="text-sm text-zinc-500">Использует весь граф и семантическую память. Сообщения обогащают граф.</p>
    <div class="mt-4 flex-1 space-y-4 overflow-y-auto">
      <div v-for="(m, i) in messages" :key="i" :class="m.role === 'user' ? 'text-right' : ''">
        <div
          class="inline-block max-w-[80%] rounded-2xl px-4 py-3 text-sm"
          :class="m.role === 'user' ? 'bg-violet-600/30' : 'bg-white/5'"
          v-html="renderMd(m.content)"
        />
      </div>
    </div>
    <form class="mt-4 flex gap-2" @submit.prevent="send">
      <input v-model="input" class="flex-1 rounded-lg border border-white/10 bg-black/30 px-4 py-2" placeholder="Ваш вопрос..." />
      <button type="submit" class="rounded-lg bg-violet-600 px-6 hover:bg-violet-500" :disabled="loading">Спросить</button>
    </form>
  </div>
</template>
