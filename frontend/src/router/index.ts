import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'landing',
      component: () => import('@/pages/landing/LandingPage.vue'),
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/pages/auth/LoginPage.vue'),
      meta: { guest: true },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/pages/auth/RegisterPage.vue'),
      meta: { guest: true },
    },
    {
      path: '/app',
      component: () => import('@/app/layouts/AppLayout.vue'),
      meta: { requiresAuth: true },
      children: [
        { path: '', redirect: '/app/interview' },
        { path: 'interview', name: 'interview', component: () => import('@/pages/app/InterviewPage.vue') },
        { path: 'interview/:id', name: 'interview-session', component: () => import('@/pages/app/InterviewSessionPage.vue') },
        { path: 'earth', name: 'earth', component: () => import('@/pages/app/EarthPage.vue') },
        { path: 'human', name: 'human', component: () => import('@/pages/app/HumanPage.vue') },
        { path: 'sky', name: 'sky', component: () => import('@/pages/app/SkyPage.vue') },
        { path: 'autobiography', name: 'autobiography', component: () => import('@/pages/app/AutobiographyPage.vue') },
        { path: 'psychologist', name: 'psychologist', component: () => import('@/pages/app/PsychologistPage.vue') },
        { path: 'settings', name: 'settings', component: () => import('@/pages/app/SettingsPage.vue') },
        { path: 'debug', name: 'debug', component: () => import('@/pages/app/DebugPage.vue') },
      ],
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.initialized) {
    await auth.fetchUser()
  }
  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (to.meta.guest && auth.isAuthenticated) {
    return { name: 'interview' }
  }
})

export default router
