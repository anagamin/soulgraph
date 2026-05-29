<script setup lang="ts">
import { RouterLink } from 'vue-router'
import HeroSection from './sections/HeroSection.vue'
import WhatIsSection from './sections/WhatIsSection.vue'
import ThreeLayersSection from './sections/ThreeLayersSection.vue'
import AutobiographerSection from './sections/AutobiographerSection.vue'
import PatternsSection from './sections/PatternsSection.vue'
import InsightsSection from './sections/InsightsSection.vue'
import GraphPreviewSection from './sections/GraphPreviewSection.vue'
import FutureSection from './sections/FutureSection.vue'
import CtaSection from './sections/CtaSection.vue'
</script>

<template>
  <div class="relative overflow-hidden">
    <div class="pointer-events-none fixed inset-0 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-violet-900/20 via-transparent to-transparent" />
    <nav class="relative z-10 flex items-center justify-between px-8 py-6">
      <span class="font-display text-2xl text-gradient">SoulGraph</span>
      <div class="flex gap-4">
        <RouterLink to="/login" class="text-sm text-zinc-400 hover:text-white">Войти</RouterLink>
        <RouterLink to="/register" class="rounded-full bg-violet-600 px-5 py-2 text-sm font-medium hover:bg-violet-500">Начать</RouterLink>
      </div>
    </nav>

    <HeroSection />
    <WhatIsSection />
    <ThreeLayersSection />
    <AutobiographerSection />
    <PatternsSection />
    <InsightsSection />
    <GraphPreviewSection />
    <FutureSection />
    <CtaSection />
  </div>
</template>

<style scoped>
.font-display {
  font-family: var(--font-display);
}
</style>
