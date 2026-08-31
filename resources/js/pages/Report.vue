<template>
  <app-page>
    <app-stat-strip :stats="summaryStats" />

    <app-filter-bar>
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
      <b-select v-model="filters.session_id" size="is-small" @input="load">
         <option value="">All Dated Services</option>
        <option v-for="session in sessions" :key="session.id" :value="session.id">{{ session.session_title }}</option>
      </b-select>
      <b-dropdown v-model="filters.attendance_status" aria-role="list" position="is-bottom-left" @change="onStatusFilter">
        <template #trigger>
           <b-button type="is-light" size="is-small" icon-right="menu-down">Attendance Status</b-button>
        </template>
         <b-dropdown-item :value="''">All Attendance Statuses</b-dropdown-item>
        <b-dropdown-item :value="'present'">Present</b-dropdown-item>
        <b-dropdown-item :value="'absent'">Absent</b-dropdown-item>
        <b-dropdown-item :value="'excused'">Excused</b-dropdown-item>
      </b-dropdown>
      <b-select v-model="filters.ministry" size="is-small" @input="load">
        <option value="">All Ministries</option>
        <option v-for="ministry in ministries" :key="ministry" :value="ministry">{{ ministry }}</option>
      </b-select>
      <template #actions>
        <b-button type="is-light" size="is-small" :loading="downloading" @click="downloadPdf">Download PDF</b-button>
        <b-button type="is-light" size="is-small" @click="resetFilters">Clear</b-button>
        <b-button type="is-dark" size="is-small" :loading="loading" @click="load">Run Report</b-button>
      </template>
    </app-filter-bar>

    <div class="report-view-tabs" role="tablist" aria-label="Report view">
      <button
        v-for="view in reportViews"
        :key="view.id"
        :id="`report-tab-${view.id}`"
        type="button"
        role="tab"
        class="report-view-tab"
        :class="{ 'is-active': activeView === view.id }"
        :aria-selected="activeView === view.id ? 'true' : 'false'"
        aria-controls="report-view-panel"
        @click="activeView = view.id"
      >
        {{ view.label }}
      </button>
    </div>

    <app-table-panel id="report-view-panel" grow role="tabpanel" :aria-labelledby="`report-tab-${activeView}`">
      <template #meta>
        <template v-if="activeView === 'sunday'">
           <span class="table-meta-title">Sunday Member Attendance</span>
           <span class="report-table-note">Each member counts once per Sunday, even across multiple services.</span>
        </template>
         <span v-else-if="activeView === 'youth'" class="table-meta-title">Youth Service Attendance</span>
        <template v-else>
           <span class="table-meta-title">Attendance by Dated Service · <strong>{{ bySession.length }}</strong></span>
          <span v-if="loading" class="loading-text">Loading...</span>
        </template>
      </template>

      <div v-if="activeView === 'sunday' && sundayRows.length" class="member-table-wrap">
        <b-table :data="sundayRows" hoverable class="page-table">
          <b-table-column label="Sunday" field="date" sortable>
            <template v-slot="props">{{ props.row.date }}</template>
          </b-table-column>
           <b-table-column label="Members Attending" field="unique_attendees" numeric>
            <template v-slot="props">{{ props.row.present }}</template>
          </b-table-column>
           <b-table-column label="Service Attendances" field="service_participations" numeric>
            <template v-slot="props">{{ props.row.service_participations }}</template>
          </b-table-column>
           <b-table-column label="No Sunday Attendance" field="missed" numeric>
            <template v-slot="props">{{ props.row.missed }}</template>
          </b-table-column>
          <b-table-column label="Rate" field="rate">
            <template v-slot="props">
              <div class="rate-cell">
                <div class="rate-track">
                  <div class="rate-fill" :style="{ width: (props.row.rate || 0) + '%' }"></div>
                </div>
                <span class="rate-value">{{ props.row.rate }}%</span>
              </div>
            </template>
          </b-table-column>
        </b-table>
      </div>

      <div v-else-if="activeView === 'sunday' && !loading" class="empty-state">
        <p class="empty-title">No Sunday data</p>
        <p class="empty-desc">No Sunday sessions were found in the selected range.</p>
      </div>

      <div v-else-if="activeView === 'youth' && youthRows.length" class="member-table-wrap">
        <b-table :data="youthRows" hoverable class="page-table">
          <b-table-column label="Sunday" field="date" sortable>
            <template v-slot="props">{{ props.row.date }}</template>
          </b-table-column>
          <b-table-column label="Attended Youth" field="attended_youth" numeric>
            <template v-slot="props">{{ props.row.attended_youth }}</template>
          </b-table-column>
          <b-table-column label="Worship Only" field="worship_only" numeric>
            <template v-slot="props">{{ props.row.worship_only }}</template>
          </b-table-column>
           <b-table-column label="No Sunday Attendance" field="no_sunday_scan" numeric>
            <template v-slot="props">{{ props.row.no_sunday_scan }}</template>
          </b-table-column>
        </b-table>
      </div>

      <div v-else-if="activeView === 'youth' && !loading" class="empty-state">
        <p class="empty-title">No youth data</p>
        <p class="empty-desc">No youth members or youth Sundays were found in the selected range.</p>
      </div>

      <div v-else-if="activeView === 'sessions' && bySession.length" class="member-table-wrap">
        <b-table :data="bySession" hoverable :loading="loading" class="page-table">
           <b-table-column label="Dated Service" field="session_title" sortable>
            <template v-slot="props">
              <span class="cell-title">{{ props.row.session_title }}</span>
            </template>
          </b-table-column>
          <b-table-column label="Date" field="session_date" sortable width="110">
            <template v-slot="props">{{ props.row.session_date }}</template>
          </b-table-column>
           <b-table-column label="People Present" field="participation_count" numeric width="100">
            <template v-slot="props">{{ props.row.participation_count }}</template>
          </b-table-column>
           <b-table-column label="Eligible Present" field="expected_present_count" numeric width="105">
            <template v-slot="props">{{ props.row.expected_present_count }} / {{ props.row.eligible_member_count }}</template>
          </b-table-column>
          <b-table-column label="Guest / Other" field="guest_other_count" numeric width="105">
            <template v-slot="props">{{ props.row.guest_other_count }}</template>
          </b-table-column>
           <b-table-column label="Eligible-Member Rate" field="attendance_rate" width="180">
            <template v-slot="props">
              <div class="rate-cell">
                <div class="rate-track">
                  <div class="rate-fill" :style="{ width: (props.row.attendance_rate || 0) + '%' }"></div>
                </div>
                <span class="rate-value">{{ props.row.attendance_rate }}%</span>
              </div>
            </template>
          </b-table-column>
        </b-table>
      </div>

      <div v-else-if="activeView === 'sessions' && !loading" class="empty-state">
        <p class="empty-title">No records in range</p>
        <p class="empty-desc">Adjust the filters to see attendance breakdown by session.</p>
      </div>
    </app-table-panel>
  </app-page>
</template>

<script>
import { api, errorMessage, downloadFile } from '../services/api';
import { formatDate, parseISODate } from '../utils/format';

export default {
  name: 'Report',

  data() {
    return {
      bySession: [],
      sessions: [],
      summaryData: { statuses: {}, total: 0, rate: 0 },
      sundayData: { overview: {}, rows: [] },
      generalData: { overview: {}, rows: [] },
      youthData: { overview: {}, rows: [] },
      filters: { date_from: '', date_to: '', session_id: '', attendance_status: '', ministry: '' },
      loading: false,
      downloading: false,
      loadRequestSeq: 0,
      sessionsRequestSeq: 0,
      activeView: 'sunday',
      reportViews: [
         { id: 'sunday', label: 'Sunday Attendance' },
         { id: 'youth', label: 'Youth Attendance' },
         { id: 'sessions', label: 'By Dated Service' },
      ],
    };
  },

  computed: {
    ministries() {
      return Array.isArray(this.summaryData.ministries) ? this.summaryData.ministries : [];
    },

    sundayOverview() {
      return this.sundayData.overview || {};
    },

    sundayRows() {
      return Array.isArray(this.sundayData.rows) ? this.sundayData.rows : [];
    },

    youthRows() {
      return Array.isArray(this.youthData.rows) ? this.youthData.rows : [];
    },

    summaryStats() {
      const sunday = this.sundayOverview;
      return [
        { label: 'Members', value: sunday.active_members || 0 },
         { label: 'Sunday Member-Days', value: sunday.unique_attendee_days || 0 },
         { label: 'Service Attendances', value: sunday.service_participations || 0 },
         { label: 'All-Date Member-Days', value: (this.generalData.overview || {}).unique_attendee_days || 0 },
        { label: 'Sundays', value: sunday.sundays || 0 },
        { label: 'Sunday Rate', value: (sunday.rate || 0) + '%', emph: true },
      ];
    },
  },

  created() {
    this.load();
    this.loadSessions();
  },

  beforeDestroy() {
    this.loadRequestSeq += 1;
    this.sessionsRequestSeq += 1;
  },

  methods: {
    formatDate,
    parseISODate,
    filterParams() {
      const params = {};
      if (this.filters.date_from) params.date_from = this.filters.date_from;
      if (this.filters.date_to) params.date_to = this.filters.date_to;
      if (this.filters.session_id) params.session_id = this.filters.session_id;
      if (this.filters.attendance_status) params.attendance_status = this.filters.attendance_status;
      if (this.filters.ministry) params.ministry = this.filters.ministry;
      return params;
    },
    async loadSessions() {
      const requestId = ++this.sessionsRequestSeq;
      try {
        const payload = await api.get('/api/attendance-sessions');
        if (requestId !== this.sessionsRequestSeq) return;
        this.sessions = payload.data || [];
      } catch (e) {
        if (requestId !== this.sessionsRequestSeq) return;
        this.$buefy.toast.open({ message: errorMessage(e, 'Could not load sessions.'), type: 'is-danger' });
      }
    },
    async load() {
      const requestId = ++this.loadRequestSeq;
      const params = this.filterParams();
      this.loading = true;
      try {
        const summaryPayload = await api.get('/api/report/summary', params);
        if (requestId !== this.loadRequestSeq) return;
        this.summaryData = summaryPayload.data || this.summaryData;
        this.bySession = Array.isArray(this.summaryData.by_session) ? this.summaryData.by_session : [];
        this.sundayData = this.summaryData.sunday || { overview: {}, rows: [] };
        this.generalData = this.summaryData.general || { overview: {}, rows: [] };
        this.youthData = this.summaryData.youth || { overview: {}, rows: [] };
      } catch (e) {
        if (requestId !== this.loadRequestSeq) return;
        this.$buefy.toast.open({ message: errorMessage(e, 'Could not load report.'), type: 'is-danger' });
      } finally {
        if (requestId === this.loadRequestSeq) this.loading = false;
      }
    },
    onStatusFilter(value) {
      this.filters.attendance_status = value;
      this.load();
    },
    resetFilters() {
      this.filters = { date_from: '', date_to: '', session_id: '', attendance_status: '', ministry: '' };
      this.load();
    },
    async downloadPdf() {
      this.downloading = true;
      try {
        await downloadFile('/api/report/pdf', 'attendance-report.pdf', this.filterParams());
      } catch (e) {
        this.$buefy.toast.open({ message: errorMessage(e, 'Could not generate PDF.'), type: 'is-danger' });
      } finally {
        this.downloading = false;
      }
    },
  },
};
</script>

<style scoped>
.rate-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.rate-track {
  flex: 1;
  height: 6px;
  background: #eef0f2;
}

.rate-fill {
  height: 100%;
  background: #29323a;
}

.rate-value {
  width: 38px;
  text-align: right;
  font-size: 11px;
  font-weight: 600;
  color: #374151;
}

.report-view-tabs {
  display: flex;
  gap: 24px;
  margin: 2px 0 8px;
  border-bottom: 1px solid #dfe3e8;
}

.report-view-tab {
  margin: 0 0 -1px;
  padding: 8px 1px 7px;
  color: #6b7280;
  background: transparent;
  border: 0;
  border-bottom: 2px solid transparent;
  border-radius: 0;
  font: inherit;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}

.report-view-tab:hover { color: #29323a; }
.report-view-tab.is-active { color: #20262c; border-bottom-color: #29323a; }
.report-view-tab:focus-visible { outline: 2px solid #59636e; outline-offset: 2px; }
.table-meta-title { color: #3f4750; font-weight: 600; }
.report-table-note { color: #8a9199; font-weight: 400; }

@media (max-width: 640px) {
  .report-view-tabs { gap: 18px; }
  .report-table-note { text-align: right; }
  .rate-cell { min-width: 150px; }
}
</style>
