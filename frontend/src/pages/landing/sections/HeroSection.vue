<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <section class="relative flex min-h-[90vh] flex-col items-center justify-center px-6 text-center">
    <div class="absolute inset-0 overflow-hidden">
      <div class="animate-pulse-slow absolute left-1/4 top-1/4 h-96 w-96 rounded-full bg-violet-600/10 blur-3xl" />
      <div class="animate-pulse-slow absolute right-1/4 bottom-1/4 h-80 w-80 rounded-full bg-amber-500/10 blur-3xl delay-1000" />
    </div>
    <p class="relative text-sm uppercase tracking-[0.3em] text-violet-400">Когнитивный граф личности</p>
    <h1 class="relative mt-6 max-w-4xl font-display text-5xl leading-tight md:text-7xl">
      <span class="text-gradient">Ваша биография</span><br />имеет три измерения
    </h1>
    <p class="relative mt-8 max-w-2xl text-lg text-zinc-400">
      SoulGraph — не дневник и не терапевт. Это система построения живого графа: событий, переживаний, идентичностей и их эволюции во времени.
    </p>
    <div class="relative mt-10 flex gap-4">
      <RouterLink to="/register" class="rounded-full bg-violet-600 px-8 py-3 font-medium hover:bg-violet-500">Создать граф</RouterLink>
      <a href="#layers" class="rounded-full border border-white/20 px-8 py-3 text-zinc-300 hover:bg-white/5">Узнать больше</a>
    </div>
  </section>
</template>

<style scoped>
.font-display {
  font-family: var(--font-display);
}
@keyframes pulse-slow {
  0%, 100% { opacity: 0.4; transform: scale(1); }
  50% { opacity: 0.7; transform: scale(1.05); }
}
.animate-pulse-slow {
  animation: pulse-slow 8s ease-in-out infinite;
}
.delay-1000 { animation-delay: 1s; }
</style>
