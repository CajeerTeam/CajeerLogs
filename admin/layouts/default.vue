<template>
  <main class="shell">
    <nav class="nav">
      <NuxtLink to="/">Обзор</NuxtLink>
      <NuxtLink to="/logs">Логи</NuxtLink>
      <NuxtLink to="/incidents">Инциденты</NuxtLink>
      <NuxtLink to="/extensions">Расширения</NuxtLink>
      <NuxtLink to="/system">Система</NuxtLink>
      <NuxtLink to="/settings">Настройки</NuxtLink>
    </nav>
    <slot />
  </main>
</template>

<style scoped>
.shell { max-width: 1180px; margin: 0 auto; padding: 24px; font-family: system-ui, sans-serif; }
.nav { display: flex; gap: 16px; margin-bottom: 24px; }
</style>
