<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const email = ref('')
const password = ref('')
const error = ref('')

async function submit() {
  error.value = ''
  try {
    await auth.login(email.value, password.value)
    router.push((route.query.redirect as string) || '/app/interview')
  } catch {
    error.value = 'Неверный email или пароль'
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center px-4">
    <form class="glass w-full max-w-md rounded-2xl p-8" @submit.prevent="submit">
      <h1 class="font-display text-3xl text-gradient">Вход</h1>
      <p v-if="error" class="mt-4 text-sm text-red-400">{{ error }}</p>
      <label class="mt-6 block text-sm text-zinc-400">Email</label>
      <input v-model="email" type="email" required class="mt-1 w-full rounded-lg border border-white/10 bg-black/30 px-4 py-2" />
      <label class="mt-4 block text-sm text-zinc-400">Пароль</label>
      <input v-model="password" type="password" required class="mt-1 w-full rounded-lg border border-white/10 bg-black/30 px-4 py-2" />
      <button type="submit" class="mt-6 w-full rounded-lg bg-violet-600 py-2 hover:bg-violet-500" :disabled="auth.loading">Войти</button>
      <p class="mt-4 text-center text-sm text-zinc-500">
        Нет аккаунта? <RouterLink to="/register" class="text-violet-400">Регистрация</RouterLink>
      </p>
    </form>
  </div>
</template>

<style scoped>
.font-display { font-family: var(--font-display); }
</style>
