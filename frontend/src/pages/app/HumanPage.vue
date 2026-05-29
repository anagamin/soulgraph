<script setup lang="ts">
import { onMounted, ref } from 'vue'
import cytoscape from 'cytoscape'
import coseBilkent from 'cytoscape-cose-bilkent'
import api from '@/shared/api/client'

cytoscape.use(coseBilkent)

const container = ref<HTMLElement | null>(null)

onMounted(async () => {
  const { data } = await api.get('/human/bridge')
  if (!container.value) return

  const nodes = (data.nodes ?? []) as { id: string; label: string; layer: string }[]
  const nodeIds = new Set(nodes.map((n) => n.id))
  const edges = (data.edges ?? []).filter(
    (e: { source: string; target: string }) => nodeIds.has(e.source) && nodeIds.has(e.target),
  ) as { id: string; source: string; target: string; type: string }[]

  const elements = [
    ...nodes.map((n) => ({
      data: { id: n.id, label: n.label, layer: n.layer },
    })),
    ...edges.map((e) => ({
      data: { id: e.id, source: e.source, target: e.target, label: e.type },
    })),
  ]

  cytoscape({
    container: container.value,
    elements,
    style: [
      {
        selector: 'node',
        style: {
          label: 'data(label)',
          'background-color': (ele) => (ele.data('layer') === 'human' ? '#a78bfa' : '#f59e0b'),
          color: '#fff',
          'font-size': 9,
          width: 45,
          height: 45,
        },
      },
      {
        selector: 'edge',
        style: {
          label: 'data(label)',
          'font-size': 8,
          'line-color': '#71717a',
          'target-arrow-color': '#71717a',
          'target-arrow-shape': 'triangle',
          'curve-style': 'bezier',
        },
      },
    ],
    layout: { name: 'cose-bilkent' },
  })
})
</script>

<template>
  <div class="glass rounded-2xl p-6">
    <h2 class="font-display text-2xl">Человек — эмоциональный мост</h2>
    <p class="mt-2 text-sm text-zinc-500">Переживания, интерпретации, мотивации</p>
    <div ref="container" class="mt-6 h-[calc(100vh-14rem)] w-full rounded-xl border border-white/5" />
  </div>
</template>

<style scoped>
.font-display { font-family: var(--font-display); }
</style>
