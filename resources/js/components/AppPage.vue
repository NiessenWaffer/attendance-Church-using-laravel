<template>
  <section class="app-page">
    <slot />
  </section>
</template>

<script>
export default {
  name: 'AppPage',
};
</script>

<style scoped>
.app-page {
  height: calc(100vh - 56px);
  height: calc(100dvh - 56px);
  padding: 12px 14px;
  color: #20262e;
  font-family: Inter, "Segoe UI", Arial, sans-serif;
  background: #f5f6f7;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

@media (max-width: 900px) {
  .app-page {
    height: auto;
    min-height: 100%;
    overflow: visible;
  }
}

@media (max-width: 640px) {
  .app-page {
    padding: 10px;
  }
}

</style>
