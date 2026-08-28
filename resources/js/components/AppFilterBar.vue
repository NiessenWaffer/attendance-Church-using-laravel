<template>
  <div class="filter-bar">
    <slot />
    <div v-if="$slots.actions" class="filter-actions">
      <slot name="actions" />
    </div>
  </div>
</template>

<script>
export default {
  name: 'AppFilterBar',
};
</script>
