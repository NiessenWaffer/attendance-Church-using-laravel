<template>
  <div class="summary-grid">
    <div
      v-for="card in cards"
      :key="card.key || card.label"
      class="summary-card"
      :class="{
        clickable: !!card.onClick,
        'is-active-filter': !!card.active,
        'no-pointer': !!card.noPointer,
      }"
      @click="handleCardClick(card)"
    >
      <span class="summary-label">{{ card.label }}</span>
      <strong class="summary-value">{{ card.value }}</strong>
    </div>
    <slot />
  </div>
</template>

<script>
export default {
  name: 'AppSummaryGrid',
  props: {
    cards: {
      type: Array,
      default: () => [],
    },
  },

  methods: {
    handleCardClick(card) {
      if (card && typeof card.onClick === 'function') {
        card.onClick();
      }
    },
  },
};
</script>
