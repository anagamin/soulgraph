<script setup lang="ts">
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()

const nav = [
  { to: '/app/interview', label: 'Интервью' },
  { to: '/app/earth', label: 'Земля' },
  { to: '/app/human', label: 'Человек' },
  { to: '/app/sky', label: 'Небо' },
  { to: '/app/autobiography', label: 'Автобиография' },
  { to: '/app/psychologist', label: 'Психолог ИИ' },
  { to: '/app/settings', label: 'Настройки' },
  { to: '/app/debug', label: 'Debug' },
]
</script>

<template>
  <div class="flex min-h-screen">
    <aside class="glass fixed inset-y-0 left-0 z-20 flex w-64 flex-col border-r border-white/10 p-6">
      <RouterLink to="/" class="font-display text-2xl text-gradient">SoulGraph</RouterLink>
      <p class="mt-1 text-xs text-zinc-500">Когнитивный граф</p>

      <nav class="mt-10 flex flex-1 flex-col gap-1">
        <RouterLink
          v-for="item in nav"
          :key="item.to"
          :to="item.to"
          class="rounded-lg px-3 py-2 text-sm transition"
          :class="route.path.startsWith(item.to) ? 'bg-violet-500/20 text-violet-200' : 'text-zinc-400 hover:bg-white/5 hover:text-zinc-200'"
        >
          {{ item.label }}
        </RouterLink>
      </nav>

      <div class="border-t border-white/10 pt-4">
        <p class="truncate text-sm text-zinc-400">{{ auth.user?.name }}</p>
        <button class="mt-2 text-xs text-zinc-500 hover:text-zinc-300" @click="auth.logout()">Выйти</button>
      </div>
    </aside>

    <div class="ml-64 flex flex-1 flex-col">
      <header class="glass sticky top-0 z-10 border-b border-white/10 px-8 py-4">
        <h1 class="font-display text-xl text-zinc-200">{{ nav.find((n) => route.path.startsWith(n.to))?.label ?? 'SoulGraph' }}</h1>
      </header>
      <main class="flex-1 p-8">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
.font-display {
  font-family: var(--font-display);
}
</style>
