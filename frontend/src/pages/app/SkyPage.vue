<script setup lang="ts">
import { onMounted, ref } from 'vue'
import cytoscape, { type NodeSingular } from 'cytoscape'
import coseBilkent from 'cytoscape-cose-bilkent'
import api from '@/shared/api/client'

cytoscape.use(coseBilkent)

const container = ref<HTMLElement | null>(null)
const patterns = ref<unknown[]>([])

onMounted(async () => {
  const [{ data: graph }, { data: pat }] = await Promise.all([
    api.get('/sky/graph'),
    api.get('/sky/patterns'),
  ])
  patterns.value = pat.patterns ?? []

  if (!container.value) return

  const cy = cytoscape({
    container: container.value,
    elements: [
      ...(graph.nodes ?? []).map((n: { id: string; label: string; type: string; confidence?: number }) => ({
        data: { id: n.id, label: n.label, type: n.type, confidence: n.confidence ?? 0.5 },
      })),
      ...(graph.edges ?? []).map((e: { source: string; target: string; type: string }, i: number) => ({
        data: { id: `e${i}`, source: e.source, target: e.target, type: e.type },
      })),
    ],
    style: [
      {
        selector: 'node',
        style: {
          label: 'data(label)',
          'background-color': '#7c3aed',
          opacity: (ele: NodeSingular) => (ele.data('confidence') as number) ?? 0.8,
          color: '#e4e4e7',
          'font-size': 10,
          width: 55,
          height: 55,
        },
      },
      {
        selector: 'edge[type = "evolves_into"]',
        style: { 'line-color': '#38bdf8', width: 2 },
      },
      {
        selector: 'edge',
        style: {
          'line-color': '#52525b',
          'target-arrow-shape': 'triangle',
          'target-arrow-color': '#52525b',
          'curve-style': 'bezier',
        },
      },
    ],
    layout: { name: 'cose-bilkent' },
  })

  cy.on('tap', 'node', (evt) => {
    const node = evt.target
    node.animate({ style: { 'border-width': 3, 'border-color': '#f59e0b' } }, { duration: 300 })
  })
})
</script>

<template>
  <div class="grid gap-4 lg:grid-cols-4">
    <div ref="container" class="glass h-[calc(100vh-10rem)] rounded-2xl lg:col-span-3" />
    <aside class="glass rounded-2xl p-4">
      <h3 class="text-sm text-sky-400">Паттерны</h3>
      <ul class="mt-4 space-y-2 text-sm">
        <li v-for="(p, i) in patterns" :key="i" class="rounded border border-white/5 p-2">
          {{ (p as { label?: string }).label }}
        </li>
        <li v-if="!patterns.length" class="text-zinc-600">Паттерны появятся по мере интервью</li>
      </ul>
    </aside>
  </div>
</template>
