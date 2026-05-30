<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import api from '@/shared/api/client'
import type {
  EarthCatalog,
  EarthEntity,
  EarthEntityDetail,
  EarthTab,
} from '@/features/earth/types'
import {
  ENTITY_TYPE_LABELS,
  RELATION_TYPE_LABELS,
} from '@/features/earth/types'
import MergeCandidatesBanner from '@/features/entities/MergeCandidatesBanner.vue'

const loading = ref(true)
const catalog = ref<EarthCatalog | null>(null)
const activeTab = ref<EarthTab>('timeline')
const selectedId = ref<string | null>(null)
const detail = ref<EarthEntityDetail | null>(null)
const detailLoading = ref(false)
const search = ref('')

const tabs: { id: EarthTab; label: string; count: () => number }[] = [
  { id: 'timeline', label: 'Таймлайн', count: () => (catalog.value?.timeline.length ?? 0) },
  { id: 'events', label: 'События', count: () => catalog.value?.events.length ?? 0 },
  { id: 'people', label: 'Люди', count: () => catalog.value?.people.length ?? 0 },
  { id: 'places', label: 'Места', count: () => catalog.value?.places.length ?? 0 },
  { id: 'relationships', label: 'Связи', count: () => catalog.value?.relationships.length ?? 0 },
]

const listEntities = computed((): EarthEntity[] => {
  if (!catalog.value) return []
  const map: Record<Exclude<EarthTab, 'timeline'>, EarthEntity[]> = {
    events: catalog.value.events,
    people: catalog.value.people,
    places: catalog.value.places,
    relationships: catalog.value.relationships,
  }
  const items = activeTab.value === 'timeline' ? [] : map[activeTab.value]
  const q = search.value.trim().toLowerCase()
  if (!q) return items
  return items.filter((e) => e.label.toLowerCase().includes(q))
})

function typeColor(type: string): string {
  const colors: Record<string, string> = {
    event: '#f59e0b',
    epoch: '#d97706',
    person: '#38bdf8',
    place: '#34d399',
    relationship: '#f472b6',
  }
  return colors[type] ?? '#a1a1aa'
}

function relationLabel(type: string, direction: 'outgoing' | 'incoming'): string {
  const base = RELATION_TYPE_LABELS[type] ?? type
  return direction === 'incoming' ? `← ${base}` : `${base} →`
}

async function loadCatalog() {
  loading.value = true
  try {
    const { data } = await api.get<EarthCatalog>('/earth/catalog')
    catalog.value = data
  } finally {
    loading.value = false
  }
}

async function selectEntity(id: string) {
  if (selectedId.value === id && detail.value) return
  selectedId.value = id
  detailLoading.value = true
  try {
    const { data } = await api.get<EarthEntityDetail>(`/earth/entities/${id}`)
    detail.value = data
  } finally {
    detailLoading.value = false
  }
}

function closeDetail() {
  selectedId.value = null
  detail.value = null
}

function excerpt(text: string, max = 120): string {
  const clean = text.replace(/\s+/g, ' ').trim()
  return clean.length <= max ? clean : `${clean.slice(0, max)}…`
}

onMounted(loadCatalog)
</script>

<template>
  <div>
    <MergeCandidatesBanner @merged="loadCatalog" />
    <div class="earth-layout">
    <section class="glass earth-main">
      <header class="earth-header">
        <div>
          <h2 class="font-display text-2xl">Земля — база фактов</h2>
          <p class="mt-1 text-sm text-zinc-500">
            События на таймлайне жизни, люди, места и перекрёстные ссылки
          </p>
        </div>
        <button
          class="rounded-lg border border-white/10 px-3 py-1.5 text-xs text-zinc-400 transition hover:bg-white/5 hover:text-zinc-200"
          @click="loadCatalog"
        >
          Обновить
        </button>
      </header>

      <nav class="earth-tabs">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          class="earth-tab"
          :class="{ active: activeTab === tab.id }"
          @click="activeTab = tab.id; search = ''"
        >
          {{ tab.label }}
          <span class="tab-count">{{ tab.count() }}</span>
        </button>
      </nav>

      <div v-if="activeTab !== 'timeline'" class="earth-search">
        <input
          v-model="search"
          type="search"
          placeholder="Поиск…"
          class="search-input"
        >
      </div>

      <div v-if="loading" class="earth-empty">Загрузка каталога…</div>

      <!-- Timeline -->
      <div v-else-if="activeTab === 'timeline'" class="timeline">
        <div v-if="!catalog?.timeline.length" class="earth-empty">
          <p>Таймлайн пока пуст.</p>
          <p class="mt-2 text-xs">
            Рассказывайте в интервью о событиях — AI будет уточнять «когда это было?» и привязывать факты ко времени.
          </p>
        </div>

        <div v-for="group in catalog?.timeline" :key="group.label" class="timeline-group">
          <div class="timeline-marker">
            <span class="timeline-dot" />
            <span class="timeline-label">{{ group.label }}</span>
          </div>
          <div class="timeline-items">
            <button
              v-for="item in group.items"
              :key="item.id"
              class="entity-card"
              :class="{ selected: selectedId === item.id }"
              @click="selectEntity(item.id)"
            >
              <span class="entity-type" :style="{ color: typeColor(item.type) }">
                {{ ENTITY_TYPE_LABELS[item.type] }}
              </span>
              <span class="entity-label">{{ item.label }}</span>
              <p v-if="item.payload.summary" class="entity-summary">
                {{ excerpt(String(item.payload.summary), 160) }}
              </p>
              <div class="entity-meta">
                <span v-if="item.related_count">{{ item.related_count }} связей</span>
                <span v-if="item.confidence">{{ Math.round(item.confidence * 100) }}%</span>
              </div>
            </button>
          </div>
        </div>
      </div>

      <!-- Entity lists -->
      <div v-else class="entity-grid">
        <div v-if="!listEntities.length" class="earth-empty">
          {{ search ? 'Ничего не найдено' : 'Пока пусто — данные появятся из интервью' }}
        </div>
        <button
          v-for="entity in listEntities"
          :key="entity.id"
          class="entity-card"
          :class="{ selected: selectedId === entity.id }"
          @click="selectEntity(entity.id)"
        >
          <span class="entity-type" :style="{ color: typeColor(entity.type) }">
            {{ ENTITY_TYPE_LABELS[entity.type] }}
          </span>
          <span class="entity-label">{{ entity.label }}</span>
          <p v-if="entity.temporal.display !== 'Без даты'" class="entity-date">
            {{ entity.temporal.display }}
          </p>
          <p v-if="entity.payload.summary" class="entity-summary">
            {{ excerpt(String(entity.payload.summary), 120) }}
          </p>
          <div class="entity-meta">
            <span v-if="entity.related_count">{{ entity.related_count }} связей</span>
          </div>
        </button>
      </div>
    </section>

    <!-- Detail panel -->
    <aside v-if="selectedId" class="glass earth-detail">
      <div class="detail-header">
        <button class="close-btn" @click="closeDetail">×</button>
      </div>

      <div v-if="detailLoading" class="earth-empty">Загрузка…</div>

      <template v-else-if="detail">
        <span class="entity-type" :style="{ color: typeColor(detail.entity.type) }">
          {{ ENTITY_TYPE_LABELS[detail.entity.type] }}
        </span>
        <h3 class="detail-title">{{ detail.entity.label }}</h3>

        <p v-if="detail.entity.temporal.display !== 'Без даты'" class="detail-date">
          {{ detail.entity.temporal.display }}
          <span v-if="detail.entity.temporal.life_period" class="text-zinc-500">
            · {{ detail.entity.temporal.life_period }}
          </span>
        </p>

        <section v-if="detail.summary" class="detail-section">
          <h4>Сводка</h4>
          <p class="detail-text">{{ detail.summary }}</p>
        </section>

        <section v-if="detail.related.length" class="detail-section">
          <h4>Связанное</h4>
          <ul class="related-list">
            <li v-for="rel in detail.related" :key="rel.relation_id">
              <button class="related-link" @click="selectEntity(rel.entity.id)">
                <span class="related-type">{{ relationLabel(rel.relation_type, rel.direction) }}</span>
                <span class="related-label">{{ rel.entity.label }}</span>
                <span class="related-entity-type">{{ ENTITY_TYPE_LABELS[rel.entity.type] }}</span>
              </button>
            </li>
          </ul>
        </section>

        <section v-if="detail.phrases.length" class="detail-section">
          <h4>Ваши слова</h4>
          <blockquote
            v-for="phrase in detail.phrases"
            :key="phrase.id"
            class="phrase"
          >
            {{ phrase.content }}
          </blockquote>
        </section>

        <section
          v-if="Object.keys(detail.entity.payload).length"
          class="detail-section"
        >
          <h4>Детали</h4>
          <dl class="payload-list">
            <template v-for="(value, key) in detail.entity.payload" :key="String(key)">
              <template v-if="value && key !== 'summary' && key !== 'label'">
                <dt>{{ key }}</dt>
                <dd>{{ value }}</dd>
              </template>
            </template>
          </dl>
        </section>
      </template>
    </aside>
    </div>
  </div>
</template>

<style scoped>
.font-display { font-family: var(--font-display); }

.earth-layout {
  display: grid;
  gap: 1rem;
  grid-template-columns: 1fr;
}

@media (min-width: 1024px) {
  .earth-layout:has(.earth-detail) {
    grid-template-columns: 1fr 22rem;
  }
}

.earth-main {
  border-radius: 1rem;
  padding: 1.5rem;
  min-height: calc(100vh - 10rem);
}

.earth-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.earth-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  margin-top: 1.5rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  padding-bottom: 0.5rem;
}

.earth-tab {
  display: flex;
  align-items: center;
  gap: 0.375rem;
  border-radius: 0.5rem;
  padding: 0.375rem 0.75rem;
  font-size: 0.8125rem;
  color: #71717a;
  transition: all 0.15s;
}

.earth-tab:hover { color: #d4d4d8; background: rgba(255, 255, 255, 0.04); }
.earth-tab.active { color: #fbbf24; background: rgba(245, 158, 11, 0.12); }

.tab-count {
  font-size: 0.6875rem;
  opacity: 0.7;
}

.earth-search { margin-top: 1rem; }

.search-input {
  width: 100%;
  max-width: 20rem;
  border-radius: 0.5rem;
  border: 1px solid rgba(255, 255, 255, 0.08);
  background: rgba(0, 0, 0, 0.2);
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
  color: #e4e4e7;
}

.earth-empty {
  margin-top: 3rem;
  text-align: center;
  color: #52525b;
  font-size: 0.875rem;
}

/* Timeline */
.timeline { margin-top: 1.5rem; }

.timeline-group {
  display: grid;
  grid-template-columns: 7rem 1fr;
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.timeline-marker {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  padding-top: 0.75rem;
  border-right: 2px solid rgba(245, 158, 11, 0.3);
  padding-right: 1rem;
}

.timeline-dot {
  width: 0.625rem;
  height: 0.625rem;
  border-radius: 50%;
  background: #f59e0b;
  margin-bottom: 0.375rem;
}

.timeline-label {
  font-size: 0.8125rem;
  font-weight: 500;
  color: #fbbf24;
  text-align: right;
}

.timeline-items {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

/* Entity cards */
.entity-grid {
  display: grid;
  gap: 0.75rem;
  margin-top: 1.5rem;
  grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
}

.entity-card {
  text-align: left;
  border-radius: 0.75rem;
  border: 1px solid rgba(255, 255, 255, 0.06);
  background: rgba(0, 0, 0, 0.15);
  padding: 0.875rem 1rem;
  transition: border-color 0.15s, background 0.15s;
  cursor: pointer;
}

.entity-card:hover {
  border-color: rgba(245, 158, 11, 0.3);
  background: rgba(245, 158, 11, 0.04);
}

.entity-card.selected {
  border-color: rgba(245, 158, 11, 0.5);
  background: rgba(245, 158, 11, 0.08);
}

.entity-type {
  font-size: 0.6875rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.entity-label {
  display: block;
  margin-top: 0.25rem;
  font-size: 0.9375rem;
  font-weight: 500;
  color: #e4e4e7;
}

.entity-date {
  margin-top: 0.25rem;
  font-size: 0.75rem;
  color: #71717a;
}

.entity-summary {
  margin-top: 0.375rem;
  font-size: 0.8125rem;
  color: #a1a1aa;
  line-height: 1.4;
}

.entity-meta {
  display: flex;
  gap: 0.75rem;
  margin-top: 0.5rem;
  font-size: 0.6875rem;
  color: #52525b;
}

/* Detail panel */
.earth-detail {
  border-radius: 1rem;
  padding: 1.25rem;
  max-height: calc(100vh - 10rem);
  overflow-y: auto;
  position: sticky;
  top: 6rem;
}

.detail-header {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 0.5rem;
}

.close-btn {
  font-size: 1.25rem;
  line-height: 1;
  color: #71717a;
  padding: 0.25rem 0.5rem;
}

.close-btn:hover { color: #e4e4e7; }

.detail-title {
  margin-top: 0.25rem;
  font-size: 1.25rem;
  font-weight: 600;
  color: #fafafa;
  font-family: var(--font-display);
}

.detail-date {
  margin-top: 0.375rem;
  font-size: 0.8125rem;
  color: #fbbf24;
}

.detail-section {
  margin-top: 1.25rem;
}

.detail-section h4 {
  font-size: 0.6875rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #71717a;
  margin-bottom: 0.5rem;
}

.detail-text {
  font-size: 0.875rem;
  color: #d4d4d8;
  line-height: 1.5;
}

.related-list {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.related-link {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.375rem;
  width: 100%;
  text-align: left;
  border-radius: 0.5rem;
  border: 1px solid rgba(255, 255, 255, 0.05);
  padding: 0.5rem 0.625rem;
  transition: background 0.15s;
}

.related-link:hover { background: rgba(255, 255, 255, 0.04); }

.related-type { font-size: 0.6875rem; color: #71717a; }
.related-label { font-size: 0.875rem; color: #e4e4e7; font-weight: 500; }
.related-entity-type { font-size: 0.6875rem; color: #52525b; }

.phrase {
  margin-bottom: 0.75rem;
  border-left: 2px solid rgba(245, 158, 11, 0.4);
  padding-left: 0.75rem;
  font-size: 0.8125rem;
  color: #a1a1aa;
  font-style: italic;
  line-height: 1.5;
}

.payload-list {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.25rem 0.75rem;
  font-size: 0.8125rem;
}

.payload-list dt { color: #71717a; }
.payload-list dd { color: #d4d4d8; }
</style>
