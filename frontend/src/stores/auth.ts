import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api, { initCsrf } from '@/shared/api/client'

export interface User {
  id: number
  name: string
  email: string
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const loading = ref(false)
  const initialized = ref(false)

  const isAuthenticated = computed(() => !!user.value)

  async function fetchUser(): Promise<void> {
    try {
      const { data } = await api.get('/user')
      user.value = data.user
    } catch {
      user.value = null
    } finally {
      initialized.value = true
    }
  }

  async function register(name: string, email: string, password: string, password_confirmation: string): Promise<void> {
    loading.value = true
    await initCsrf()
    const { data } = await api.post('/register', { name, email, password, password_confirmation })
    user.value = data.user
    loading.value = false
  }

  async function login(email: string, password: string): Promise<void> {
    loading.value = true
    await initCsrf()
    const { data } = await api.post('/login', { email, password })
    user.value = data.user
    loading.value = false
  }

  async function logout(): Promise<void> {
    await api.post('/logout')
    user.value = null
  }

  return { user, loading, initialized, isAuthenticated, fetchUser, register, login, logout }
})
