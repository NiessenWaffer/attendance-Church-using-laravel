<template>
  <section class="scan-route-loading">Opening scanner...</section>
</template>

<script>
export default {
  name: 'ScanPageRoute',
  mounted() {
    window.location.assign('/scan');
  },
};
</script>

<style scoped>
.scan-route-loading {
  padding: 32px;
  text-align: center;
}
</style>
