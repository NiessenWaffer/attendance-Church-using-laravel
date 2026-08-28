<template>
  <div class="table-panel" :class="{ 'app-table-panel--grow': grow }">
    <div v-if="$slots.meta" class="table-meta">
      <slot name="meta" />
    </div>
    <slot />
  </div>
</template>

<script>
export default {
  name: 'AppTablePanel',
  props: {
    grow: {
      type: Boolean,
      default: true,
    },
  },
};
</script>

<style scoped>
.app-table-panel--grow {
  flex: 1;
  min-height: 0;
  height: auto;
}
</style>
