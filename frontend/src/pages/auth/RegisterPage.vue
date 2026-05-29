<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const name = ref('')
const email = ref('')
const password = ref('')
const password_confirmation = ref('')
const error = ref('')

async function submit() {
  error.value = ''
  try {
    await auth.register(name.value, email.value, password.value, password_confirmation.value)
    router.push('/app/interview')
  } catch (e: unknown) {
    error.value = 'Ошибка регистрации. Проверьте данные.'
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center px-4">
    <form class="glass w-full max-w-md rounded-2xl p-8" @submit.prevent="submit">
      <h1 class="font-display text-3xl text-gradient">Регистрация</h1>
      <p v-if="error" class="mt-4 text-sm text-red-400">{{ error }}</p>
      <label class="mt-6 block text-sm text-zinc-400">Имя</label>
      <input v-model="name" required class="mt-1 w-full rounded-lg border border-white/10 bg-black/30 px-4 py-2" />
      <label class="mt-4 block text-sm text-zinc-400">Email</label>
      <input v-model="email" type="email" required class="mt-1 w-full rounded-lg border border-white/10 bg-black/30 px-4 py-2" />
      <label class="mt-4 block text-sm text-zinc-400">Пароль</label>
      <input v-model="password" type="password" required minlength="8" class="mt-1 w-full rounded-lg border border-white/10 bg-black/30 px-4 py-2" />
      <label class="mt-4 block text-sm text-zinc-400">Подтверждение</label>
      <input v-model="password_confirmation" type="password" required class="mt-1 w-full rounded-lg border border-white/10 bg-black/30 px-4 py-2" />
      <button type="submit" class="mt-6 w-full rounded-lg bg-violet-600 py-2 hover:bg-violet-500" :disabled="auth.loading">Создать аккаунт</button>
      <p class="mt-4 text-center text-sm text-zinc-500">
        Уже есть аккаунт? <RouterLink to="/login" class="text-violet-400">Войти</RouterLink>
      </p>
    </form>
  </div>
</template>

<style scoped>
.font-display { font-family: var(--font-display); }
</style>
