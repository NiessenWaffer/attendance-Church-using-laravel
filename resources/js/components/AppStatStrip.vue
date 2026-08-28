<template>
  <div class="stat-strip">
    <div
      v-for="stat in stats"
      :key="stat.key || stat.label"
      class="stat-strip-item"
      :class="{ 'stat-strip-item--emph': stat.emph }"
    >
      <span class="stat-strip-label">{{ stat.label }}</span>
      <strong class="stat-strip-value">{{ stat.value }}</strong>
    </div>
    <slot />
  </div>
</template>

<script>
export default {
  name: 'AppStatStrip',
  props: {
    stats: {
      type: Array,
      default: () => [],
    },
  },
};
</script>
