<template>
  <div class="sidebar-shell">
    <aside class="sidebar" :class="{ 'is-open': open }">
      <ul class="menu-list">
        <li v-for="item in menu" :key="item.path">
          <router-link :to="item.path" :exact="item.exact" @click.native="$emit('close')">
            <b-icon :icon="item.icon" size="is-small"></b-icon>
            <span>{{ item.label }}</span>
          </router-link>
        </li>
      </ul>
    </aside>
    <div v-if="open" class="sidebar-backdrop" @click="$emit('close')"></div>
  </div>
</template>

<script>
import { api } from '../services/api';

const DEFAULT_MENU = [
  { path: '/', label: 'Dashboard', icon: 'view-dashboard-outline', exact: true },
  { path: '/members', label: 'Members', icon: 'account-group-outline' },
  { path: '/schedules', label: 'Service Schedules', icon: 'calendar-clock-outline' },
  { path: '/attendance', label: 'Dated Services', icon: 'clipboard-check-outline' },
  { path: '/history', label: 'Attendance Records', icon: 'history' },
  { path: '/report', label: 'Attendance Reports', icon: 'file-chart-outline' },
  { path: '/audit-logs', label: 'Audit Logs', icon: 'file-clock-outline' },
  { path: '/settings', label: 'Settings', icon: 'cog-outline' },
];

export default {
  name: 'SideBar',

  props: {
    open: {
      type: Boolean,
      default: false,
    },
  },

  data() {
    return {
      menu: DEFAULT_MENU.map((item) => ({ ...item })),
      sidebarUpdatedHandler: null,
    };
  },

  created() {
    this.loadMenu();
    this.sidebarUpdatedHandler = () => this.loadMenu();
    this.$root.$on('sidebar-updated', this.sidebarUpdatedHandler);
  },

  beforeDestroy() {
    this.$root.$off('sidebar-updated', this.sidebarUpdatedHandler);
  },

  methods: {
    async loadMenu() {
      try {
        const payload = await api.get('/api/sidebar');
        const items = payload.data || [];
        const visibleItems = Array.isArray(items)
          ? items.filter((item) => item.enabled !== false)
          : [];

        // Never replace a working menu with an empty configuration.
        if (visibleItems.length) {
          this.menu = visibleItems;
        }
      } catch (e) {
        this.menu = DEFAULT_MENU.map((item) => ({ ...item }));
      }
    },
  },
};
</script>

<style scoped>
.sidebar {
  position: fixed;
  top: 56px;
  left: 0;
  width: var(--sidebar-w, 250px);
  height: calc(100vh - 56px);
  box-sizing: border-box;
  overflow-y: auto;
  background: #101827;
  padding: 20px 12px;
  border-right: 1px solid #1f2b40;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
  z-index: 999;
  transition: width 0.2s ease, transform 0.25s ease;
}

.sidebar-backdrop {
  display: none;
}

.menu-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.menu-list li a {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 10px;
  padding: 11px 13px;
  color: #aab5c7;
  font-size: 13px;
  font-weight: 500;
  text-decoration: none;
  text-align: left;
  border-radius: 0;
  transition: all 0.15s ease;
}

.menu-list li a:hover {
  background: #1b2940;
  color: #ffffff;
}

.menu-list li a.router-link-active {
  background: #dbe7ff;
  color: #172033;
  font-weight: 600;
}

.menu-list li a.router-link-active ::v-deep .icon {
  color: #111827;
}

/* Tablet / small laptop: collapse to an icon rail */
@media (max-width: 1180px) {
  .sidebar {
    padding: 16px 8px;
  }

  .menu-list li a {
    justify-content: center;
    padding: 12px 0;
    gap: 0;
  }

  .menu-list li a span {
    display: none;
  }
}

/* Mobile: slide-out drawer */
@media (max-width: 768px) {
  .sidebar {
    top: 48px;
    height: calc(100vh - 48px);
    height: calc(100dvh - 48px);
    transform: translateX(-100%);
    box-shadow: 6px 0 24px rgba(15, 23, 42, 0.18);
  }

  .sidebar.is-open {
    width: 250px;
    transform: translateX(0);
    padding: 20px 12px max(20px, env(safe-area-inset-bottom));
  }

  .sidebar.is-open .menu-list li a {
    justify-content: flex-start;
    padding: 11px 13px;
    gap: 10px;
  }

  .sidebar.is-open .menu-list li a span {
    display: block;
  }

  .sidebar-backdrop {
    display: block;
    position: fixed;
    top: 48px;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(15, 23, 42, 0.45);
    z-index: 998;
  }
}
</style>
