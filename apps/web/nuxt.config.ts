// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },
  css: ['~/assets/css/main.css'],
  runtimeConfig: {
    apiServerBase: process.env.NUXT_API_SERVER_BASE || process.env.API_SERVER_BASE || 'http://127.0.0.1:8000/api/v1',
    public: {
      appName: process.env.NUXT_PUBLIC_APP_NAME || 'Trading Studio',
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://127.0.0.1:8000/api/v1',
    },
  },
})
