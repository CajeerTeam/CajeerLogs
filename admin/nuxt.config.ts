export default defineNuxtConfig({
  compatibilityDate: '2026-06-15',
  devtools: { enabled: true },
  typescript: { strict: true },
  app: {
    head: {
      htmlAttrs: { lang: 'ru' },
      title: 'CajeerLogs Admin'
    }
  }
})
