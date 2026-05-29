<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <div class="glass max-w-lg rounded-2xl p-6">
    <h2 class="text-lg font-medium">Настройки</h2>
    <dl class="mt-6 space-y-4 text-sm">
      <div>
        <dt class="text-zinc-500">Имя</dt>
        <dd>{{ auth.user?.name }}</dd>
      </div>
      <div>
        <dt class="text-zinc-500">Email</dt>
        <dd>{{ auth.user?.email }}</dd>
      </div>
    </dl>
    <p class="mt-8 text-xs text-zinc-600">Сброс пароля — в следующей версии.</p>
  </div>
</template>
