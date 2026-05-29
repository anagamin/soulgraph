<script setup lang="ts">
import { onMounted, ref } from 'vue'
import cytoscape from 'cytoscape'
import coseBilkent from 'cytoscape-cose-bilkent'

cytoscape.use(coseBilkent)

const container = ref<HTMLElement | null>(null)

onMounted(() => {
  if (!container.value) return

  cytoscape({
    container: container.value,
    elements: [
      { data: { id: 'a', label: 'Детство' } },
      { data: { id: 'b', label: 'Страх' } },
      { data: { id: 'c', label: 'Идентичность' } },
      { data: { id: 'd', label: 'Освобождение' } },
      { data: { id: 'ab', source: 'a', target: 'b' } },
      { data: { id: 'bc', source: 'b', target: 'c' } },
      { data: { id: 'cd', source: 'c', target: 'd' } },
    ],
    style: [
      {
        selector: 'node',
        style: {
          label: 'data(label)',
          'background-color': '#7c3aed',
          color: '#e4e4e7',
          'font-size': 10,
          'text-valign': 'center',
          width: 50,
          height: 50,
        },
      },
      {
        selector: 'edge',
        style: {
          'line-color': '#52525b',
          'target-arrow-color': '#52525b',
          'target-arrow-shape': 'triangle',
          width: 1,
          'curve-style': 'bezier',
        },
      },
    ],
    layout: { name: 'cose-bilkent' },
    userZoomingEnabled: false,
  })
})
</script>

<template>
  <section class="mx-auto max-w-5xl px-6 py-24">
    <h2 class="text-center font-display text-4xl">Интерактивный граф</h2>
    <div ref="container" class="glass mt-12 h-80 w-full rounded-2xl" />
  </section>
</template>

<style scoped>
.font-display { font-family: var(--font-display); }
</style>
