<template>
  <app-page>
    <app-stat-strip :stats="summaryStats" />

    <app-filter-bar>
      <input type="text" class="input audit-search" v-model="filters.search" placeholder="Search user, action, description..." />
      <b-select v-model="filters.module" size="is-small">
        <option value="">All Modules</option>
        <option v-for="module in modules" :key="module" :value="module">{{ module }}</option>
      </b-select>
      <div class="filter-date-range">
        <span class="filter-inline-label">From</span>
        <b-datepicker
          :value="parseISODate(filters.date_from)"
          @input="(v) => { filters.date_from = v ? formatDate(v) : ''; load(); }"
          placeholder="From"
          format="MMM d, yyyy"
          size="is-small"
          icon="calendar-blank-outline"
        />
        <span class="filter-inline-label">To</span>
        <b-datepicker
          :value="parseISODate(filters.date_to)"
          @input="(v) => { filters.date_to = v ? formatDate(v) : ''; load(); }"
          placeholder="To"
          format="MMM d, yyyy"
          size="is-small"
          icon="calendar-blank-outline"
        />
      </div>
      <template #actions>
          <b-button type="is-light" size="is-small" :loading="exporting" @click="exportLogs">Export CSV</b-button>
          <b-button type="is-light" size="is-small" @click="clearFilters">Clear</b-button>
          <b-button type="is-dark" size="is-small" :loading="loading" @click="load">Refresh</b-button>
        </template>
    </app-filter-bar>

    <app-table-panel>
      <template #meta>
        <span>Showing <strong>{{ logs.length }}</strong> audit entr{{ logs.length === 1 ? 'y' : 'ies' }}</span>
        <span v-if="loading" class="loading-text">Loading...</span>
      </template>

      <div v-if="logs.length" class="audit-feed">
        <article v-for="log in logs" :key="log.id" class="audit-entry">
          <div class="audit-marker" :class="moduleClass(log.module)"></div>
          <div class="audit-entry-main">
            <div class="audit-entry-head">
              <span class="module-tag" :class="moduleClass(log.module)">{{ log.module || 'system' }}</span>
              <time>{{ formatDateTime(log.created_at) }}</time>
            </div>
            <div class="audit-entry-title"><strong>{{ log.username || 'System' }}</strong> performed <code>{{ log.action }}</code></div>
            <p v-if="log.description">{{ log.description }}</p>
            <small v-if="log.ip_address">{{ log.ip_address }}<span v-if="log.user_id"> · user #{{ log.user_id }}</span></small>
          </div>
        </article>
      </div>

      <div v-else-if="!loading" class="empty-state">
        <p class="empty-title">No audit entries</p>
        <p class="empty-desc">Actions you perform across the app will appear here.</p>
      </div>
    </app-table-panel>
  </app-page>
</template>

<script>
import { api, errorMessage, downloadFile } from '../services/api';
import { formatDate, parseISODate } from '../utils/format';

const MODULE_CLASSES = {
  auth: 'module-auth',
  member: 'module-member',
  event: 'module-event',
  attendance: 'module-attendance',
  schedule: 'module-schedule',
  settings: 'module-settings',
};

export default {
  name: 'AuditLogs',

  data() {
    return {
      logs: [],
      modules: [],
      filters: { search: '', module: '', date_from: '', date_to: '' },
      loading: false,
      exporting: false,
    };
  },

  computed: {
    summary() {
      const users = new Set();
      const modules = new Set();
      this.logs.forEach((log) => {
        if (log.username) users.add(log.username);
        if (log.module) modules.add(log.module);
      });
      return { users: users.size, modules: modules.size };
    },
    summaryStats() {
      return [
        { label: 'Total Entries', value: this.logs.length },
        { label: 'Users', value: this.summary.users },
        { label: 'Modules', value: this.summary.modules },
      ];
    },
  },

  created() {
    this.loadModules();
    this.load();
  },

  methods: {
    formatDate,
    parseISODate,
    formatDateTime(value) {
      if (!value) return '';
      const date = new Date(value.replace(' ', 'T'));
      if (Number.isNaN(date.getTime())) return value;
      return date.toLocaleString([], {
        month: 'short',
        day: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
      });
    },
    moduleClass(module) {
      return MODULE_CLASSES[module] || 'module-default';
    },
    clearFilters() {
      this.filters = { search: '', module: '', date_from: '', date_to: '' };
      this.load();
    },
    async loadModules() {
      try {
        const payload = await api.get('/api/audit-logs/modules');
        this.modules = payload.data || [];
      } catch (e) {
        // non-fatal
      }
    },
    async load() {
      this.loading = true;
      try {
        const params = {};
        if (this.filters.search) params.search = this.filters.search;
        if (this.filters.module) params.module = this.filters.module;
        if (this.filters.date_from) params.date_from = this.filters.date_from;
        if (this.filters.date_to) params.date_to = this.filters.date_to;
        const payload = await api.get('/api/audit-logs', params);
        this.logs = payload.data || [];
      } catch (e) {
        this.$buefy.toast.open({ message: errorMessage(e, 'Could not load audit logs.'), type: 'is-danger' });
      } finally {
        this.loading = false;
      }
    },
    async exportLogs() {
      this.exporting = true;
      try {
        const params = {};
        if (this.filters.search) params.search = this.filters.search;
        if (this.filters.module) params.module = this.filters.module;
        if (this.filters.date_from) params.date_from = this.filters.date_from;
        if (this.filters.date_to) params.date_to = this.filters.date_to;
        await downloadFile('/api/audit-logs/export', 'audit-logs.csv', params);
      } catch (e) {
        this.$buefy.toast.open({ message: errorMessage(e, 'Could not export audit logs.'), type: 'is-danger' });
      } finally {
        this.exporting = false;
      }
    },
  },
};
</script>

<style scoped>
.audit-search-field {
  flex: 1;
  min-width: 180px;
}

.module-tag {
  display: inline-block;
  padding: 2px 8px;
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  border: 1px solid transparent;
}

.module-auth { color: #1d4ed8; background: #dbeafe; border-color: #bfdbfe; }
.module-member { color: #7e22ce; background: #f3e8ff; border-color: #e9d5ff; }
.module-event { color: #15803d; background: #dcfce7; border-color: #bbf7d0; }
.module-attendance { color: #b45309; background: #fef3c7; border-color: #fde68a; }
.module-schedule { color: #0e7490; background: #cffafe; border-color: #a5f3fc; }
.module-settings { color: #475569; background: #f1f5f9; border-color: #e2e8f0; }
.module-default { color: #374151; background: #f3f4f6; border-color: #e5e7eb; }

.action-code {
  font-size: 11px;
  color: #334155;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  padding: 2px 6px;
}

.audit-feed { display: flex; flex-direction: column; gap: 0; max-width: 920px; margin: 4px auto; }
.audit-entry { position: relative; display: flex; gap: 15px; padding: 0 0 17px 4px; }
.audit-entry:not(:last-child)::before { content: ''; position: absolute; left: 9px; top: 17px; bottom: 0; width: 1px; background: #e3e9f2; }
.audit-marker { z-index: 1; flex: 0 0 11px; width: 11px; height: 11px; margin-top: 4px; border: 3px solid #fff; border-radius: 50%; box-shadow: 0 0 0 1px #bdc8d8; }
.audit-marker.module-auth { background: #4f83d1; }
.audit-marker.module-member { background: #a66fd1; }
.audit-marker.module-event { background: #4da875; }
.audit-marker.module-attendance { background: #d18a37; }
.audit-marker.module-schedule { background: #39a0b0; }
.audit-entry-main { flex: 1; min-width: 0; padding: 0 0 15px; border-bottom: 1px solid #edf1f6; }
.audit-entry-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 7px; }
.audit-entry-head time { color: #8b96aa; font-size: 10px; }
.audit-entry-title { color: #4c5a70; font-size: 12px; }
.audit-entry-title strong { color: #1c2b43; }
.audit-entry-title code { color: #31598e; background: #f0f5ff; border-radius: 4px; font-size: 11px; }
.audit-entry-main p { margin: 6px 0 0; color: #788398; font-size: 11px; }
.audit-entry-main > small { display: block; margin-top: 8px; color: #a0aabc; font-size: 10px; }

@media (max-width: 640px) {
  .audit-entry { gap: 10px; }
  .audit-entry-head { align-items: center; flex-direction: row; gap: 8px; }
  .audit-entry-title { overflow-wrap: anywhere; }
}
</style>
