<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <section class="mx-auto max-w-3xl px-6 py-32 text-center">
    <h2 class="font-display text-4xl text-gradient">Начните исследование себя</h2>
    <p class="mt-4 text-zinc-400">Первый шаг — одно честное интервью.</p>
    <RouterLink to="/register" class="mt-8 inline-block rounded-full bg-violet-600 px-10 py-4 font-medium hover:bg-violet-500">
      Создать SoulGraph
    </RouterLink>
  </section>
</template>

<style scoped>
.font-display { font-family: var(--font-display); }
</style>
