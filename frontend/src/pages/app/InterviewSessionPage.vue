<script setup lang="ts">
import { onMounted, ref, computed, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { marked } from 'marked'
import api, { initCsrf } from '@/shared/api/client'

interface Message {
  id: string
  role: string
  content: string
  reasoning_metadata?: { patterns?: unknown[]; hypotheses?: unknown[]; entities_count?: number }
  processing_status?: string
}

const route = useRoute()
const sessionId = computed(() => route.params.id as string)
const messages = ref<Message[]>([])
const input = ref('')
const streaming = ref(false)
const streamContent = ref('')
const entities = ref<unknown[]>([])
const chatRef = ref<HTMLElement | null>(null)

async function load() {
  const { data } = await api.get(`/interview/sessions/${sessionId.value}`)
  messages.value = data.data?.messages ?? data.messages ?? []
  const ext = await api.get(`/interview/sessions/${sessionId.value}/extractions`)
  entities.value = ext.data.entities ?? []
}

async function send() {
  if (!input.value.trim() || streaming.value) return
  const content = input.value
  input.value = ''
  streaming.value = true
  streamContent.value = ''

  messages.value.push({ id: 'tmp-u', role: 'user', content })

  await initCsrf()
  const response = await fetch(`/api/v1/interview/sessions/${sessionId.value}/messages/stream`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream' },
    credentials: 'include',
    body: JSON.stringify({ content }),
  })

  const reader = response.body?.getReader()
  const decoder = new TextDecoder()
  if (!reader) return

  while (true) {
    const { done, value } = await reader.read()
    if (done) break
    const text = decoder.decode(value)
    for (const line of text.split('\n')) {
      if (!line.startsWith('data: ')) continue
      const payload = line.slice(6)
      if (payload === '[DONE]') continue
      try {
        const parsed = JSON.parse(payload)
        streamContent.value += parsed.content ?? ''
        await nextTick()
        chatRef.value?.scrollTo(0, chatRef.value.scrollHeight)
      } catch { /* skip */ }
    }
  }

  messages.value.push({ id: 'tmp-a', role: 'assistant', content: streamContent.value })
  streamContent.value = ''
  streaming.value = false
  await load()
}

function renderMd(text: string) {
  return marked(text, { async: false }) as string
}

onMounted(load)
</script>

<template>
  <div class="grid h-[calc(100vh-8rem)] gap-4 lg:grid-cols-4">
    <div ref="chatRef" class="glass flex flex-col overflow-y-auto rounded-2xl p-4 lg:col-span-3">
      <div v-for="m in messages" :key="m.id" class="mb-4" :class="m.role === 'user' ? 'text-right' : ''">
        <div
          class="inline-block max-w-[85%] rounded-2xl px-4 py-3 text-sm"
          :class="m.role === 'user' ? 'bg-violet-600/30' : 'bg-white/5'"
          v-html="renderMd(m.content)"
        />
      </div>
      <div v-if="streaming && streamContent" class="mb-4">
        <div class="inline-block max-w-[85%] rounded-2xl bg-white/5 px-4 py-3 text-sm" v-html="renderMd(streamContent)" />
      </div>
      <form class="mt-auto flex gap-2 border-t border-white/10 pt-4" @submit.prevent="send">
        <textarea v-model="input" rows="2" class="flex-1 resize-none rounded-lg border border-white/10 bg-black/30 px-4 py-2" placeholder="Ваш ответ..." />
        <button type="submit" class="rounded-lg bg-violet-600 px-4 hover:bg-violet-500" :disabled="streaming">Отправить</button>
      </form>
    </div>

    <aside class="glass rounded-2xl p-4">
      <h3 class="text-sm font-medium text-zinc-400">Извлечённые сущности</h3>
      <ul class="mt-4 space-y-2 text-xs">
        <li v-for="(e, i) in entities" :key="i" class="rounded border border-white/5 p-2">
          {{ (e as { canonical_label?: string }).canonical_label ?? '—' }}
        </li>
        <li v-if="!entities.length" class="text-zinc-600">Появятся после обработки сообщений</li>
      </ul>
    </aside>
  </div>
</template>
