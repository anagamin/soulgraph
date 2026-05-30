<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/shared/api/client'

const auth = useAuthStore()
const router = useRouter()
const resetting = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const birthYear = ref<number | ''>('')
const birthPlace = ref('')

async function loadProfile() {
  await auth.fetchUser()
  birthYear.value = auth.user?.birth_year ?? ''
  birthPlace.value = auth.user?.birth_place ?? ''
}

async function saveProfile() {
  saving.value = true
  error.value = null
  try {
    const { data } = await api.patch('/settings/profile', {
      birth_year: birthYear.value === '' ? null : birthYear.value,
      birth_place: birthPlace.value || null,
    })
    auth.user = data.user
  } catch {
    error.value = 'Не удалось сохранить профиль.'
  } finally {
    saving.value = false
  }
}

async function startOver() {
  const confirmed = window.confirm(
    'Удалить все данные о себе?\n\n' +
      'Будут удалены интервью, граф, автобиографии, сессии с психологом и все связанные записи. ' +
      'Аккаунт и вход сохранятся. Это действие нельзя отменить.',
  )

  if (!confirmed) {
    return
  }

  resetting.value = true
  error.value = null

  try {
    await api.post('/settings/reset', { confirm: true })
    await loadProfile()
    await router.push('/app/interview')
  } catch {
    error.value = 'Не удалось сбросить данные. Попробуйте ещё раз.'
  } finally {
    resetting.value = false
  }
}

onMounted(loadProfile)
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

    <div class="mt-8 border-t border-white/10 pt-8">
      <h3 class="text-sm font-medium text-zinc-300">Якоря жизни</h3>
      <p class="mt-2 text-xs text-zinc-500">
        Год рождения и место помогают точнее выстраивать хронологию в интервью «Общая история».
      </p>
      <div class="mt-4 space-y-3">
        <div>
          <label class="text-xs text-zinc-500">Год рождения</label>
          <input
            v-model.number="birthYear"
            type="number"
            min="1900"
            max="2100"
            placeholder="1985"
            class="mt-1 w-full rounded-lg border border-white/10 bg-black/30 px-4 py-2"
          />
        </div>
        <div>
          <label class="text-xs text-zinc-500">Место рождения / где выросли</label>
          <input
            v-model="birthPlace"
            type="text"
            placeholder="Москва"
            class="mt-1 w-full rounded-lg border border-white/10 bg-black/30 px-4 py-2"
          />
        </div>
        <button
          class="rounded-lg bg-violet-600 px-4 py-2 text-sm hover:bg-violet-500 disabled:opacity-50"
          :disabled="saving"
          @click="saveProfile"
        >
          {{ saving ? 'Сохранение…' : 'Сохранить якоря' }}
        </button>
      </div>
    </div>

    <div class="mt-10 border-t border-white/10 pt-8">
      <h3 class="text-sm font-medium text-zinc-300">Данные профиля</h3>
      <p class="mt-2 text-xs text-zinc-500">
        Удалит все интервью, сущности графа, автобиографии и сессии с психологом. Аккаунт останется.
      </p>
      <button
        class="mt-4 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm text-red-300 transition hover:bg-red-500/20 disabled:opacity-50"
        :disabled="resetting"
        @click="startOver"
      >
        {{ resetting ? 'Удаление...' : 'Начать сначала' }}
      </button>
      <p v-if="error" class="mt-3 text-xs text-red-400">{{ error }}</p>
    </div>

    <p class="mt-8 text-xs text-zinc-600">Сброс пароля — в следующей версии.</p>
  </div>
</template>
