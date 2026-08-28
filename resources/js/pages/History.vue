<template>
  <app-page>
    <!-- Follow-up mode -->
    <div v-if="followupMode">
      <div class="toolbar history-toolbar">
        <span class="history-mode-note">Active members with no attendance in the last 30 days.</span>
        <b-button type="is-light" size="is-small" @click="toggleFollowup">View All History</b-button>
        <b-button type="is-dark" size="is-small" :loading="loading" @click="load">Refresh</b-button>
      </div>

      <app-table-panel>
        <template #meta>
          <span>Showing <strong>{{ followups.length }}</strong> member(s) needing follow-up</span>
          <span v-if="loading" class="loading-text">Loading...</span>
        </template>

        <div v-if="followups.length" class="member-table-wrap">
          <b-table :data="followups" hoverable :loading="loading" class="page-table">
            <b-table-column label="Member" field="last_name" sortable>
              <template v-slot="props">
                <div class="cell-title">{{ getFullName(props.row) }}</div>
                <div class="cell-sub">{{ props.row.member_code }}</div>
              </template>
            </b-table-column>
            <b-table-column label="Status" width="150">
              <template v-slot="props">
                <span class="status-tag status-followup">Needs Follow-up</span>
              </template>
            </b-table-column>
            <b-table-column label="Follow-up note" field="followup_note">
              <template v-slot="props">
                <b-input v-model="props.row.followup_note" size="is-small" placeholder="Add a note"></b-input>
              </template>
            </b-table-column>
            <b-table-column label="Action" width="120">
              <template v-slot="props">
                <b-button size="is-small" type="is-light" :loading="savingFollowup === props.row.member_code" @click="saveFollowup(props.row)">
                  {{ props.row.followup_status === 'completed' ? 'Reopen' : 'Save' }}
                </b-button>
              </template>
            </b-table-column>
            <b-table-column label="" width="140">
              <template v-slot="props">
                <router-link
                  :to="{ path: '/history', query: { member_code: props.row.member_code } }"
                  class="history-link-btn"
                >
                  Open History
                </router-link>
              </template>
            </b-table-column>
          </b-table>
        </div>

        <div v-else-if="!loading" class="empty-state">
          <p class="empty-title">No follow-ups right now</p>
          <p class="empty-desc">All active members attended a service in the last 30 days.</p>
        </div>
      </app-table-panel>
    </div>

    <!-- History mode -->
    <template v-else>
      <app-stat-strip :stats="summaryStats" />

      <app-filter-bar>
        <b-select v-model="filters.member_code" size="is-small" @input="onMemberFilter">
          <option value="">All Members</option>
          <option v-for="member in members" :key="member.member_code" :value="member.member_code">
            {{ getFullName(member) }} ({{ member.member_code }})
          </option>
        </b-select>

        <b-dropdown v-model="filters.attendance_status" aria-role="list" position="is-bottom-left" @change="onStatusFilter">
          <template #trigger>
            <b-button type="is-light" size="is-small" icon-right="menu-down">Status</b-button>
          </template>
          <b-dropdown-item :value="''">All Status</b-dropdown-item>
          <b-dropdown-item :value="'present'">Present</b-dropdown-item>
          <b-dropdown-item :value="'absent'">Absent</b-dropdown-item>
          <b-dropdown-item :value="'excused'">Excused</b-dropdown-item>
        </b-dropdown>

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
          <b-button type="is-light" size="is-small" :loading="exporting" @click="exportRecords">Export CSV</b-button>
          <b-button type="is-light" size="is-small" @click="toggleFollowup">Needs Follow-Up</b-button>
          <b-button type="is-dark" size="is-small" :loading="loading" @click="load">Refresh</b-button>
        </template>
      </app-filter-bar>

      <app-table-panel>
        <template #meta>
          <span>Showing <strong>{{ records.length }}</strong> record(s)</span>
          <span v-if="loading" class="loading-text">Loading...</span>
        </template>

        <div v-if="records.length" class="member-table-wrap">
          <b-table :data="records" hoverable :loading="loading" class="page-table">
            <b-table-column label="Date" field="session_date" sortable width="110">
              <template v-slot="props">{{ props.row.session_date }}</template>
            </b-table-column>
            <b-table-column label="Session" field="session_title" sortable>
              <template v-slot="props">
                <span class="cell-title">{{ props.row.session_title }}</span>
              </template>
            </b-table-column>
            <b-table-column label="Member" field="last_name" sortable>
              <template v-slot="props">
                <div class="cell-title">{{ getFullName(props.row) }}</div>
                <div class="cell-sub">{{ props.row.member_code }}</div>
              </template>
            </b-table-column>
            <b-table-column label="Status" field="attendance_status" sortable width="110">
              <template v-slot="props">
                <span class="status-tag" :class="statusClass(props.row.attendance_status)">
                  {{ props.row.attendance_status }}
                </span>
              </template>
            </b-table-column>
            <b-table-column label="Check-in" field="check_in_time" width="130">
              <template v-slot="props">{{ formatTime(props.row.check_in_time) }}</template>
            </b-table-column>
            <b-table-column label="Remarks" field="remarks">
              <template v-slot="props">
                <span class="cell-sub">{{ props.row.remarks || '' }}</span>
              </template>
            </b-table-column>
          </b-table>
        </div>

        <div v-else-if="!loading" class="empty-state">
          <p class="empty-title">No records found</p>
          <p class="empty-desc">Adjust the filters or record attendance first.</p>
        </div>
      </app-table-panel>
    </template>
  </app-page>
</template>

<script>
import { api, errorMessage, downloadFile } from '../services/api';
import { getFullName, formatDate, parseISODate } from '../utils/format';

export default {
  name: 'History',

  data() {
    return {
      followups: [],
      records: [],
      members: [],
      filters: { member_code: '', attendance_status: '', date_from: '', date_to: '' },
      loading: false,
      followupMode: false,
      exporting: false,
      savingFollowup: null,
      loadRequestSeq: 0,
    };
  },

  computed: {
    summary() {
      const counts = { present: 0, absent: 0, excused: 0 };
      this.records.forEach((record) => {
        if (counts[record.attendance_status] !== undefined) {
          counts[record.attendance_status] += 1;
        }
      });
      const total = this.records.length;
      const attended = counts.present;
      const rate = total ? Math.round((attended / total) * 100) : 0;
      return { ...counts, total, rate };
    },
    summaryStats() {
      const s = this.summary;
      return [
        { label: 'Present', value: s.present },
        { label: 'Absent', value: s.absent },
        { label: 'Excused', value: s.excused },
        { label: 'Total', value: s.total },
        { label: 'Recorded Present', value: s.rate + '%', emph: true },
      ];
    },
  },

  created() {
    this.loadMembers();
  },

  watch: {
    '$route.query': {
      immediate: true,
      handler() {
        this.initFromRoute();
      },
    },
  },

  methods: {
    getFullName,
    formatDate,
    parseISODate,
    formatTime(value) {
      if (!value) return '';
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) return value;
      return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    },
    statusClass(status) {
      if (status === 'present') return 'status-active';
      if (status === 'absent') return 'status-inactive';
      if (status === 'excused') return 'status-followup';
      return '';
    },
    initFromRoute() {
      const query = this.$route.query || {};
      this.followupMode = query.followup === '1';
      this.filters.member_code = query.member_code || '';
      if (query.member_code) {
        this.followupMode = false;
      }
      this.load();
    },
    onMemberFilter() {
      const query = { ...(this.$route.query || {}) };
      delete query.followup;
      if (this.filters.member_code) query.member_code = this.filters.member_code;
      else delete query.member_code;

      const currentMember = this.$route.query.member_code || '';
      if (currentMember === this.filters.member_code && !this.$route.query.followup) {
        this.load();
        return;
      }
      this.$router.replace({ path: '/history', query });
    },
    onStatusFilter(value) {
      this.filters.attendance_status = value;
      this.load();
    },
    toggleFollowup() {
      if (this.followupMode) {
        this.$router.replace({ path: '/history', query: {} });
      } else {
        this.$router.replace({ path: '/history', query: { followup: '1' } });
      }
    },
    async loadMembers() {
      try {
        const payload = await api.get('/api/integration/fetch');
        this.members = payload.data.members || [];
      } catch (e) {
        this.$buefy.toast.open({ message: errorMessage(e, 'Could not load members.'), type: 'is-danger' });
      }
    },
    async exportRecords() {
      this.exporting = true;
      try {
        const params = {};
        if (this.filters.member_code) params.member_code = this.filters.member_code;
        if (this.filters.attendance_status) params.attendance_status = this.filters.attendance_status;
        if (this.filters.date_from) params.date_from = this.filters.date_from;
        if (this.filters.date_to) params.date_to = this.filters.date_to;
        await downloadFile('/api/attendance-records/export', 'attendance-records.csv', params);
      } catch (e) {
        this.$buefy.toast.open({ message: errorMessage(e, 'Could not export records.'), type: 'is-danger' });
      } finally {
        this.exporting = false;
      }
    },
    async saveFollowup(row) {
      this.savingFollowup = row.member_code;
      try {
        const nextStatus = row.followup_status === 'completed' ? 'open' : 'completed';
        const payload = await api.post('/api/attendance-records/followups', {
          external_member_id: row.member_code,
          note: row.followup_note || '',
          status: nextStatus,
          due_date: row.followup_due_date || null,
        });
        row.followup_status = nextStatus;
        this.$buefy.toast.open({ message: payload.message || 'Follow-up saved.', type: 'is-success' });
      } catch (e) {
        this.$buefy.toast.open({ message: errorMessage(e, 'Could not save follow-up.'), type: 'is-danger' });
      } finally {
        this.savingFollowup = null;
      }
    },
    async load() {
      const requestId = ++this.loadRequestSeq;
      const followupMode = this.followupMode;
      const params = {};
      if (this.filters.member_code) params.member_code = this.filters.member_code;
      if (this.filters.attendance_status) params.attendance_status = this.filters.attendance_status;
      if (this.filters.date_from) params.date_from = this.filters.date_from;
      if (this.filters.date_to) params.date_to = this.filters.date_to;
      this.loading = true;
      try {
        if (followupMode) {
          const payload = await api.get('/api/attendance-records/followups');
          if (requestId !== this.loadRequestSeq || this.followupMode !== followupMode) return;
          this.followups = payload.data || [];
        } else {
          const payload = await api.get('/api/attendance-records', params);
          if (requestId !== this.loadRequestSeq || this.followupMode !== followupMode) return;
          this.records = payload.data || [];
        }
      } catch (e) {
        if (requestId !== this.loadRequestSeq) return;
        this.$buefy.toast.open({ message: errorMessage(e, 'Could not load history.'), type: 'is-danger' });
      } finally {
        if (requestId === this.loadRequestSeq) this.loading = false;
      }
    },
  },
};
</script>

<style scoped>
.history-toolbar {
  margin-bottom: 8px;
}

.history-mode-note {
  flex: 1;
  color: #697386;
  font-size: 11px;
  line-height: 1.4;
}

.history-member-field {
  flex: 1;
}

.history-link-btn {
  display: inline-block;
  border: 1px solid #d9dde1;
  background: #ffffff;
  color: #374151;
  padding: 4px 10px;
  font-size: 11px;
  text-decoration: none;
  cursor: pointer;
}

.history-link-btn:hover {
  background: #f1f5f9;
  border-color: #cbd5e1;
}

@media (max-width: 640px) {
  .history-toolbar {
    align-items: center;
    flex-direction: row;
    flex-wrap: wrap;
  }

  .history-toolbar .button {
    width: auto;
  }

  .history-mode-note { flex: 1 1 100%; }
}
</style>
