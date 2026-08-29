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
        <option value="">All Sessions</option>
        <option v-for="session in sessions" :key="session.id" :value="session.id">{{ session.session_title }}</option>
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

    <section class="report-overview">
      <div class="report-spotlight">
        <span class="report-overline">Sunday health</span>
        <strong>{{ sundayOverview.rate || 0 }}<small>%</small></strong>
        <span>One scan in any Sunday service counts as present for that Sunday.</span>
      </div>
      <div class="report-ranking">
        <div class="report-ranking-head"><span>Youth participation</span><small>Range summary</small></div>
        <div class="ranking-list">
          <div class="ranking-row"><span>Attended Youth</span><b>{{ youthOverview.attended_youth || 0 }}</b></div>
          <div class="ranking-row"><span>Worship Only</span><b>{{ youthOverview.worship_only || 0 }}</b></div>
          <div class="ranking-row"><span>No Sunday Scan</span><b>{{ youthOverview.no_sunday_scan || 0 }}</b></div>
          <div class="ranking-row"><span>Youth Members</span><b>{{ youthOverview.youth_members || 0 }}</b></div>
        </div>
      </div>
    </section>

    <app-table-panel grow>
      <template #meta>
        <span>Sunday Attendance Health</span>
      </template>

      <div v-if="sundayRows.length" class="member-table-wrap">
        <b-table :data="sundayRows" hoverable class="page-table">
          <b-table-column label="Sunday" field="date" sortable>
            <template v-slot="props">{{ props.row.date }}</template>
          </b-table-column>
          <b-table-column label="Unique Attendees" field="unique_attendees" numeric>
            <template v-slot="props">{{ props.row.present }}</template>
          </b-table-column>
          <b-table-column label="Service Participations" field="service_participations" numeric>
            <template v-slot="props">{{ props.row.service_participations }}</template>
          </b-table-column>
          <b-table-column label="Missed" field="missed" numeric>
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

      <div v-else-if="!loading" class="empty-state">
        <p class="empty-title">No Sunday data</p>
        <p class="empty-desc">No Sunday sessions were found in the selected range.</p>
      </div>
    </app-table-panel>

    <app-table-panel grow>
      <template #meta>
        <span>Youth Participation</span>
      </template>

      <div v-if="youthRows.length" class="member-table-wrap">
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
          <b-table-column label="No Sunday Scan" field="no_sunday_scan" numeric>
            <template v-slot="props">{{ props.row.no_sunday_scan }}</template>
          </b-table-column>
        </b-table>
      </div>

      <div v-else-if="!loading" class="empty-state">
        <p class="empty-title">No youth data</p>
        <p class="empty-desc">No youth members or youth Sundays were found in the selected range.</p>
      </div>
    </app-table-panel>

    <section class="report-overview report-overview--service">
      <div class="report-ranking">
        <div class="report-ranking-head"><span>Strongest sessions</span><small>By target audience rate</small></div>
        <div v-if="bySession.length" class="ranking-list">
          <div v-for="row in strongestSessions" :key="row.id || row.session_title" class="ranking-row">
            <span>{{ row.session_title }}</span><b>{{ row.attendance_rate }}%</b>
          </div>
        </div>
        <span v-else class="report-muted">Run a report to see the ranking.</span>
      </div>
    </section>

    <!-- Attendance breakdown by session -->
    <app-table-panel grow>
      <template #meta>
        <span>Attendance by Session — <strong>{{ bySession.length }}</strong> session(s)</span>
        <span v-if="loading" class="loading-text">Loading...</span>
      </template>

      <div v-if="bySession.length" class="member-table-wrap">
        <b-table :data="bySession" hoverable :loading="loading" class="page-table">
          <b-table-column label="Session" field="session_title" sortable>
            <template v-slot="props">
              <span class="cell-title">{{ props.row.session_title }}</span>
            </template>
          </b-table-column>
          <b-table-column label="Date" field="session_date" sortable width="110">
            <template v-slot="props">{{ props.row.session_date }}</template>
          </b-table-column>
          <b-table-column label="Participants" field="participation_count" numeric width="100">
            <template v-slot="props">{{ props.row.participation_count }}</template>
          </b-table-column>
          <b-table-column label="Expected" field="expected_present_count" numeric width="90">
            <template v-slot="props">{{ props.row.expected_present_count }} / {{ props.row.eligible_member_count }}</template>
          </b-table-column>
          <b-table-column label="Guest / Other" field="guest_other_count" numeric width="105">
            <template v-slot="props">{{ props.row.guest_other_count }}</template>
          </b-table-column>
          <b-table-column label="Audience Rate" field="attendance_rate" width="180">
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

      <div v-else-if="!loading" class="empty-state">
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

    youthOverview() {
      return this.youthData.overview || {};
    },

    youthRows() {
      return Array.isArray(this.youthData.rows) ? this.youthData.rows : [];
    },

    strongestSessions() {
      return Array.isArray(this.summaryData.strongest_sessions) ? this.summaryData.strongest_sessions : [];
    },

    summaryStats() {
      const sunday = this.sundayOverview;
      return [
        { label: 'Active Members', value: sunday.active_members || 0 },
        { label: 'Sunday Unique Attendee-Days', value: sunday.unique_attendee_days || 0 },
        { label: 'Sunday Service Participations', value: sunday.service_participations || 0 },
        { label: 'All-Date Unique Attendee-Days', value: (this.generalData.overview || {}).unique_attendee_days || 0 },
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

.report-overview { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 12px; margin-bottom: 12px; }
.report-spotlight { display: flex; flex-direction: column; justify-content: space-between; min-height: 130px; padding: 18px; color: #fff; background: #243b62; }
.report-overline { color: #b8c9e7; font-size: 10px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; }
.report-spotlight strong { font-size: 32px; font-weight: 600; letter-spacing: -.03em; font-variant-numeric: tabular-nums; color: #fff; }
.report-spotlight strong small { margin-left: 2px; font-size: 15px; }
.report-spotlight > span:last-child { color: #c8d4e8; font-size: 11px; }
.report-ranking { padding: 16px; background: #fff; border: 1px solid #e8edf5; }
.report-ranking-head { display: flex; justify-content: space-between; margin-bottom: 10px; color: #28364f; font-size: 12px; font-weight: 600; }
.report-ranking-head small { color: #8b96aa; font-size: 10px; font-weight: 500; }
.ranking-list { display: flex; flex-direction: column; gap: 8px; }
.ranking-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eef2f7; color: #607089; font-size: 11px; }
.ranking-row:last-child { border-bottom: 0; }
.ranking-row b { color: #31598e; }
.report-muted { color: #8b96aa; font-size: 11px; }
@media (max-width: 900px) { .report-overview { grid-template-columns: 1fr; } }
@media (max-width: 640px) {
  .report-spotlight { min-height: 110px; padding: 14px; }
  .report-spotlight strong { font-size: 26px; }
  .report-ranking { padding: 12px; }
  .report-ranking-head { align-items: center; flex-direction: row; gap: 8px; }
  .rate-cell { min-width: 150px; }
}
</style>
