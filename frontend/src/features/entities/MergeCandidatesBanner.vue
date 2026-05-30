<script setup lang="ts">
import { onMounted, ref } from 'vue'
import api from '@/shared/api/client'
import type { MergeCandidate } from '@/features/entities/types'

const candidates = ref<MergeCandidate[]>([])
const loading = ref(true)

async function load() {
  loading.value = true
  try {
    const { data } = await api.get<{ candidates: MergeCandidate[] }>('/entities/merge-candidates')
    candidates.value = data.candidates ?? []
  } finally {
    loading.value = false
  }
}

const emit = defineEmits<{ merged: [] }>()

async function accept(id: string) {
  await api.post(`/entities/merge-candidates/${id}/accept`)
  await load()
  emit('merged')
}

async function reject(id: string) {
  await api.post(`/entities/merge-candidates/${id}/reject`)
  await load()
  emit('merged')
}

onMounted(load)

defineExpose({ reload: load })
</script>

<template>
  <section v-if="!loading && candidates.length" class="merge-banner glass">
    <h3 class="merge-title">Возможные дубликаты</h3>
    <p class="merge-sub">
      Система нашла похожие сущности. Объедините, если это одно и то же.
    </p>
    <ul class="merge-list">
      <li v-for="item in candidates" :key="item.id" class="merge-item">
        <div class="merge-pair">
          <span>{{ item.entity_a.label }}</span>
          <span class="merge-vs">↔</span>
          <span>{{ item.entity_b.label }}</span>
        </div>
        <div class="merge-meta">
          {{ Math.round(item.similarity * 100) }}% · {{ item.method }}
        </div>
        <div class="merge-actions">
          <button class="btn-accept" @click="accept(item.id)">Объединить</button>
          <button class="btn-reject" @click="reject(item.id)">Разные</button>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.merge-banner {
  margin-bottom: 1rem;
  border-radius: 0.75rem;
  padding: 1rem 1.25rem;
  border: 1px solid rgba(245, 158, 11, 0.2);
  background: rgba(245, 158, 11, 0.05);
}

.merge-title {
  font-size: 0.875rem;
  font-weight: 600;
  color: #fbbf24;
}

.merge-sub {
  margin-top: 0.25rem;
  font-size: 0.75rem;
  color: #71717a;
}

.merge-list {
  margin-top: 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.merge-item {
  border-radius: 0.5rem;
  border: 1px solid rgba(255, 255, 255, 0.06);
  padding: 0.625rem 0.75rem;
  background: rgba(0, 0, 0, 0.15);
}

.merge-pair {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.375rem;
  font-size: 0.8125rem;
  color: #e4e4e7;
}

.merge-vs { color: #71717a; }

.merge-meta {
  margin-top: 0.25rem;
  font-size: 0.6875rem;
  color: #52525b;
}

.merge-actions {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.5rem;
}

.btn-accept,
.btn-reject {
  border-radius: 0.375rem;
  padding: 0.25rem 0.625rem;
  font-size: 0.75rem;
}

.btn-accept {
  background: rgba(245, 158, 11, 0.2);
  color: #fbbf24;
}

.btn-reject {
  color: #71717a;
}

.btn-accept:hover { background: rgba(245, 158, 11, 0.3); }
.btn-reject:hover { color: #a1a1aa; }
</style>
