<script setup lang="ts">
import { onMounted, ref, shallowRef } from 'vue'
import * as echarts from 'echarts'
import api from '@/shared/api/client'

const chartRef = ref<HTMLElement | null>(null)
const chart = shallowRef<echarts.ECharts | null>(null)
const timeline = ref<{
  events?: Array<{ label: string; valid_from?: string }>
  epochs?: Array<{ label: string }>
  people?: unknown[]
  places?: unknown[]
}>({})

onMounted(async () => {
  const { data } = await api.get('/earth/timeline')
  timeline.value = data

  if (!chartRef.value) return
  chart.value = echarts.init(chartRef.value, 'dark')

  const events = [...(data.events ?? []), ...(data.epochs ?? [])]
  const items = events.map((e: { label: string; valid_from?: string }, i: number) => ({
    name: e.label,
    value: [i, 0, e.label],
  }))

  chart.value.setOption({
    backgroundColor: 'transparent',
    tooltip: {},
    xAxis: { type: 'value', show: false },
    yAxis: { type: 'category', data: ['Жизнь'], show: false },
    series: [{
      type: 'scatter',
      symbolSize: 40,
      data: items,
      itemStyle: { color: '#f59e0b' },
      label: { show: true, formatter: (p: { data: unknown[] }) => (p.data as string[])[2], position: 'top', color: '#e4e4e7', fontSize: 11 },
    }],
  })
})
</script>

<template>
  <div class="glass rounded-2xl p-6">
    <h2 class="font-display text-2xl">Земля — временная карта</h2>
    <p class="mt-2 text-sm text-zinc-500">События, эпохи, люди и места</p>
    <div ref="chartRef" class="mt-8 h-96 w-full" />
    <div class="mt-8 grid gap-4 md:grid-cols-3">
      <div>
        <h3 class="text-sm text-amber-400">События</h3>
        <p class="text-2xl font-medium">{{ timeline.events?.length ?? 0 }}</p>
      </div>
      <div>
        <h3 class="text-sm text-amber-400">Люди</h3>
        <p class="text-2xl font-medium">{{ timeline.people?.length ?? 0 }}</p>
      </div>
      <div>
        <h3 class="text-sm text-amber-400">Места</h3>
        <p class="text-2xl font-medium">{{ timeline.places?.length ?? 0 }}</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.font-display { font-family: var(--font-display); }
</style>
