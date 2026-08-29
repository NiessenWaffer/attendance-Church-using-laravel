<template>
  <section class="dashboard-page">

    <!-- Summary Stat Boxes -->
    <div class="summary-boxes">
      <div class="stat-box">
        <div class="stat-box-head">
          <b-icon icon="account-group-outline" size="is-small"></b-icon>
          <span class="stat-box-title">Members</span>
        </div>
        <div class="stat-box-body">
          <button type="button" class="stat-row" @click="goMembers('')">
            <span class="stat-row-label">Total Members</span>
            <strong class="stat-row-value">{{ members.total }}</strong>
          </button>
          <button type="button" class="stat-row" @click="goMembers('active')">
            <span class="stat-row-label">Active Members</span>
            <strong class="stat-row-value">{{ members.active }}</strong>
          </button>
          <button type="button" class="stat-row" @click="goMembers('inactive')">
            <span class="stat-row-label">Inactive Members</span>
            <strong class="stat-row-value">{{ members.inactive }}</strong>
          </button>
          <button type="button" class="stat-row is-followup" @click="goFollowups">
            <span class="stat-row-label">Needs Follow-up</span>
            <strong class="stat-row-value">{{ members.followup_30 }}</strong>
          </button>
        </div>
        <div class="stat-box-foot">
          <div class="ratio-bar"><div class="ratio-fill" :style="activeRatioStyle"></div></div>
          <span class="ratio-caption">{{ activeRatioLabel }}</span>
        </div>
      </div>

      <div class="stat-box">
        <div class="stat-box-head">
          <b-icon icon="calendar-check-outline" size="is-small"></b-icon>
          <span class="stat-box-title">Sessions</span>
        </div>
        <div class="stat-box-body">
          <button type="button" class="stat-row" @click="goSessions('')">
            <span class="stat-row-label">Total Sessions</span>
            <strong class="stat-row-value">{{ sessions.total }}</strong>
          </button>
          <button type="button" class="stat-row" @click="goSessions('open')">
            <span class="stat-row-label">Open Sessions</span>
            <strong class="stat-row-value">{{ sessions.open }}</strong>
          </button>
          <button type="button" class="stat-row" @click="goSessions('closed')">
            <span class="stat-row-label">Closed Sessions</span>
            <strong class="stat-row-value">{{ sessions.closed }}</strong>
          </button>
          <div class="stat-divider"></div>
          <button type="button" class="stat-row" :disabled="!latestSession" @click="goLatestSession">
            <span class="stat-row-label">
              Latest Session
              <span class="stat-row-sub">{{ latestSession ? latestSession.session_title : 'No session yet' }}</span>
            </span>
            <strong class="stat-row-value">
              <template v-if="latestSession">
                 {{ latestSession.expected_present_count }}<span class="stat-slash">/</span>{{ latestSession.eligible_member_count }}
                <span class="rate-badge">{{ latestSession.attendance_rate !== null ? latestSession.attendance_rate + '%' : '—' }}</span>
              </template>
              <span v-else>—</span>
            </strong>
          </button>
        </div>
      </div>
    </div>

    <!-- Analytics Section -->
    <section class="analytics-section">
        <div class="analytics-grid">
          <!-- Overall Rate -->
          <div class="analytics-panel">
            <div class="analytics-panel-title">Target Audience Attendance Rate</div>
            <div class="overall-rate-value">{{ analytics.overall_rate !== null ? analytics.overall_rate + '%' : '—' }}</div>
            <div class="overall-rate-sub">{{ analytics.expected_participations }} expected participations / {{ analytics.opportunity_total }} opportunities · {{ analytics.service_participations }} total participations · {{ analytics.unique_attendee_days }} unique attendee-days</div>
          </div>

          <!-- Attendance Trend -->
          <div class="analytics-panel">
            <div class="analytics-panel-title">Attendance Trend</div>
            <div v-if="analytics.trend.length" class="trend-chart">
              <div
                v-for="(point, i) in analytics.trend"
                :key="i"
                class="trend-col"
                :title="`${point.session_title} — ${point.expected_present_count}/${point.eligible_member_count} target audience; ${point.guest_other_count} guest/other`"
              >
                <span class="trend-value">{{ point.attendance_rate }}%</span>
                <div class="trend-bar-area">
                  <div class="trend-bar" :style="rateBarStyle(point.attendance_rate)"></div>
                </div>
                <span class="trend-label">{{ (point.session_date || '').slice(5) }}</span>
              </div>
            </div>
            <div v-else class="analytics-empty">No attendance recorded yet.</div>
          </div>

          <!-- Monthly Attendance -->
          <div class="analytics-panel">
            <div class="analytics-panel-title">Monthly Unique Attendee-Days</div>
            <div v-if="analytics.monthly.length" class="trend-chart">
              <div v-for="(m, i) in analytics.monthly" :key="i" class="trend-col">
                <span class="trend-value">{{ m.unique_attendee_days }}</span>
                <div class="trend-bar-area">
                  <div class="trend-bar is-monthly" :style="countBarStyle(m.unique_attendee_days)"></div>
                </div>
                <span class="trend-label">{{ m.label }}</span>
              </div>
            </div>
            <div v-else class="analytics-empty">No monthly data yet.</div>
          </div>

          <!-- Status Breakdown -->
          <div class="analytics-panel">
            <div class="analytics-panel-title">Status Breakdown</div>
            <div class="status-bars">
              <div v-for="s in analytics.status_breakdown" :key="s.status" class="status-row">
                <span class="status-name">{{ capitalize(s.status) }}</span>
                <div class="status-track">
                  <div class="status-fill" :style="statusBarStyle(s.count)"></div>
                </div>
                <span class="status-count">
                  {{ s.count }}<span v-if="analytics.record_total" class="status-pct"> ({{ pct(s.count) }}%)</span>
                </span>
              </div>
            </div>
            <div v-if="analytics.type_breakdown.length" class="type-chips">
              <span v-for="t in analytics.type_breakdown" :key="t.session_type" class="type-chip">
                {{ t.session_type }} · {{ t.count }}
              </span>
            </div>
          </div>
        </div>
    </section>

    <!-- Scrollable middle: Recent Sessions + Follow-ups -->
    <section class="work-area">
      <app-table-panel grow>
        <template #meta>
          <span class="table-meta-text">
            Recent Sessions — <strong>{{ recentSessions.length }}</strong>
            <span v-if="loading" class="loading-text">Loading...</span>
          </span>
        </template>

        <div v-if="recentSessions.length" class="member-table-wrap">
          <b-table
            :data="recentSessions"
            hoverable
            :loading="loading"
            class="page-table"
            @click="openAttendance"
          >
            <b-table-column field="session_title" label="Session" v-slot="props">
              <span class="member-fullname">{{ props.row.session_title }}</span>
            </b-table-column>

            <b-table-column field="session_date" label="Date" width="110" v-slot="props">
              <span class="text-neutral">{{ props.row.session_date }}</span>
            </b-table-column>

            <b-table-column field="present" label="Expected Audience" width="160" v-slot="props">
              <span class="text-neutral">{{ props.row.expected_present_count || 0 }} / {{ props.row.eligible_member_count || 0 }} expected<span v-if="props.row.guest_other_count"> · +{{ props.row.guest_other_count }} other</span></span>
            </b-table-column>

            <b-table-column field="is_closed" label="Status" width="90" v-slot="props">
              <span class="status-tag" :class="props.row.is_closed ? 'status-inactive' : 'status-active'">
                {{ props.row.is_closed ? 'Closed' : 'Open' }}
              </span>
            </b-table-column>
          </b-table>
        </div>

        <div v-else-if="!loading" class="empty-state">
          <p class="empty-title">No sessions yet</p>
          <p class="empty-desc">Create or activate a schedule, then generate a session from the Schedules page.</p>
        </div>
      </app-table-panel>

      <!-- Right Panel: Follow-ups + Celebrations -->
      <aside class="side-panel">
        <div class="detail-container">
          <div class="panel-header">
            <span class="panel-eyebrow">Follow-Up</span>
            <span class="att-count-badge">{{ followups.length }}</span>
          </div>
          <p class="followup-desc">Active members with no attendance in the last 30 days.</p>

          <div class="roster-list">
            <div
              v-for="row in followups"
              :key="row.member_code"
              class="roster-row roster-clickable"
              @click="goMemberHistory(row.member_code)"
            >
              <div class="followup-info">
                <span class="roster-name">{{ getFullName(row) }}</span>
                <span class="followup-meta">
                  {{ row.member_code || 'No code' }} · Last: {{ row.last_attendance_date || 'Never' }}
                </span>
              </div>
            </div>
            <div v-if="!followups.length" class="roster-empty">
              No members needing follow-up.
            </div>
          </div>

          <div class="panel-header celebrations-header">
            <span class="panel-eyebrow">Celebrations</span>
            <span class="att-count-badge">{{ celebrations.length }}</span>
          </div>
          <p class="followup-desc">Birthdays and membership anniversaries in the next 7 days.</p>

          <div class="roster-list">
            <div
              v-for="row in celebrations"
              :key="row.type + '-' + (row.member_code || row.id)"
              class="roster-row roster-clickable"
              @click="goMemberHistory(row.member_code || row.id)"
            >
              <div class="followup-info">
                <span class="roster-name">{{ row.name }}</span>
                <span class="followup-meta">
                  {{ formatCelebrationDate(row.date) }} · {{ row.label }}
                </span>
              </div>
            </div>
            <div v-if="!celebrations.length && !remindersLoading" class="roster-empty">
              No celebrations in the next 7 days.
            </div>
            <div v-if="remindersLoading" class="roster-empty">Loading...</div>
          </div>
        </div>
      </aside>
    </section>

    <!-- Attendance Insights (pinned below the scroll area) -->
    <section class="insights-section">
      <div class="insights-grid">
        <div class="insights-card">
          <div class="insights-card-title">Top Ministries by Attendance</div>
          <p class="insights-card-sub">Last 30 days, unique attendees</p>
          <div v-if="topMinistries.length" class="insights-list">
            <div v-for="(row, i) in topMinistries" :key="row.ministry" class="insights-row">
              <span class="insights-rank">{{ i + 1 }}</span>
              <span class="insights-name">{{ row.ministry }}</span>
              <span class="insights-value">{{ row.attendees }}</span>
            </div>
          </div>
          <p v-else-if="!insightsLoading" class="insights-empty">No ministry attendance data yet.</p>
        </div>

        <div class="insights-card">
          <div class="insights-card-title">New Members Who Attended</div>
          <p class="insights-card-sub">Joined and attended this month</p>
          <div v-if="newAttendees.length" class="insights-list">
            <div v-for="(row, i) in newAttendees" :key="row.member_code || i" class="insights-row" @click="goMemberHistory(row.member_code)">
              <span class="insights-rank">{{ i + 1 }}</span>
              <span class="insights-name">{{ getFullName(row) }}</span>
              <span class="insights-value">{{ formatJoined(row.date_joined) }}</span>
            </div>
          </div>
          <p v-else-if="!insightsLoading" class="insights-empty">No new members attended this month.</p>
        </div>

        <div class="insights-card">
          <div class="insights-card-title">Members Missing Sessions</div>
          <p class="insights-card-sub">Absent for 3+ of the last 8 sessions</p>
          <div v-if="absentStreaks.length" class="insights-list">
            <div v-for="(row, i) in absentStreaks" :key="row.member_code || i" class="insights-row" @click="goMemberHistory(row.member_code)">
              <span class="insights-rank">{{ i + 1 }}</span>
              <span class="insights-name">{{ getFullName(row) }}</span>
              <span class="insights-value">{{ row.missed_sessions }} missed</span>
            </div>
          </div>
          <p v-else-if="!insightsLoading" class="insights-empty">No members are missing sessions.</p>
        </div>
      </div>
    </section>
  </section>
</template>

<script>
import { api, errorMessage } from '../services/api';
import { getFullName } from '../utils/format';

export default {
  name: 'DashboardPage',

  data() {
    return {
      members: { total: 0, active: 0, inactive: 0, followup_30: 0 },
      sessions: { total: 0, open: 0, closed: 0 },
      latestSession: null,
      recentSessions: [],
      followups: [],
      reminders: { birthdays: [], anniversaries: [] },
      analytics: {
        overall_rate: null,
        record_total: 0,
        opportunity_total: 0,
        expected_participations: 0,
        service_participations: 0,
        guest_other_participations: 0,
        unique_attendee_days: 0,
        trend: [],
        status_breakdown: [
          { status: 'present', count: 0 },
          { status: 'absent', count: 0 },
          { status: 'excused', count: 0 },
        ],
        type_breakdown: [],
        monthly: [],
      },
      loading: false,
      loadError: '',
      insightsLoading: false,
      remindersLoading: false,
      topMinistries: [],
      newAttendees: [],
      absentStreaks: [],
    };
  },

  created() {
    this.loadSummary();
    this.loadReminders();
    this.loadInsights();
    this.refreshTimer = setInterval(() => {
      this.loadSummary();
      this.loadInsights();
    }, 30000);
  },

  beforeDestroy() {
    if (this.refreshTimer) clearInterval(this.refreshTimer);
  },

  computed: {
    celebrations() {
      const birthdays = this.reminders.birthdays.map(b => ({
        ...b,
        type: 'birthday',
        label: `Birthday · turns ${b.turning}`,
      }));
      const anniversaries = this.reminders.anniversaries.map(a => ({
        ...a,
        type: 'anniversary',
        label: `Anniversary · ${a.years} year${a.years === 1 ? '' : 's'}`,
      }));
      return [...birthdays, ...anniversaries]
        .sort((a, b) => a.date.localeCompare(b.date))
        .slice(0, 15);
    },

    activeRatioStyle() {
      const total = this.members.total || 1;
      const pct = Math.min(100, Math.round((this.members.active / total) * 100));
      return { width: pct + '%' };
    },

    activeRatioLabel() {
      const total = this.members.total || 0;
      const pct = total ? Math.round((this.members.active / total) * 100) : 0;
      return total
        ? `${this.members.active} of ${total} members active (${pct}%)`
        : 'No members yet';
    },
  },

  methods: {
    getFullName,

    async loadSummary() {
      this.loading = true;
      this.loadError = '';
      try {
        const payload = await api.get('/api/dashboard/summary');
        const data = payload && payload.data ? payload.data : {};
        this.members = data.members || this.members;
        this.sessions = data.sessions || this.sessions;
        this.latestSession = data.latest_session || null;
        this.recentSessions = Array.isArray(data.recent_sessions) ? data.recent_sessions : [];
        this.followups = Array.isArray(data.followups) ? data.followups : [];
        this.analytics = Object.assign({}, this.analytics, data.analytics || {});
      } catch (error) {
        this.loadError = errorMessage(error, 'Failed to load dashboard.');
      } finally {
        this.loading = false;
      }
    },

    async loadReminders() {
      this.remindersLoading = true;
      try {
        const payload = await api.get('/api/dashboard/reminders');
        const data = payload && payload.data ? payload.data : {};
        this.reminders = {
          birthdays: Array.isArray(data.birthdays) ? data.birthdays : [],
          anniversaries: Array.isArray(data.anniversaries) ? data.anniversaries : [],
        };
      } catch (error) {
        // Reminders are supplementary; keep the dashboard usable if they fail.
      } finally {
        this.remindersLoading = false;
      }
    },

    async loadInsights() {
      try {
        this.insightsLoading = true;
        const payload = await api.get('/api/dashboard/insights');
        const data = payload && payload.data ? payload.data : {};
        this.topMinistries = Array.isArray(data.top_ministries) ? data.top_ministries : [];
        this.newAttendees = Array.isArray(data.new_attendees) ? data.new_attendees : [];
        this.absentStreaks = Array.isArray(data.absent_streaks) ? data.absent_streaks : [];
      } catch (error) {
        // Insights are supplementary; keep the dashboard usable if they fail.
      } finally {
        this.insightsLoading = false;
      }
    },

    formatJoined(date) {
      if (!date) return '';
      return String(date).slice(0, 10);
    },

    formatCelebrationDate(date) {
      if (!date) return '';
      return new Date(`${date}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    },

    rateBarStyle(rate) {
      const height = Math.max(2, Math.round(rate || 0));
      return { height: `${height}%` };
    },

    countBarStyle(count) {
      const max = Math.max(...this.analytics.monthly.map(m => m.unique_attendee_days || 0), 1);
      const height = count > 0 ? Math.max(4, Math.round((count / max) * 100)) : 2;
      return { height: `${height}%` };
    },

    statusBarStyle(count) {
      const total = this.analytics.record_total || 1;
      const width = count > 0 ? Math.max(4, Math.round((count / total) * 100)) : 0;
      return { width: `${width}%` };
    },

    pct(count) {
      const total = this.analytics.record_total || 1;
      return total ? Math.round((count / total) * 100) : 0;
    },

    capitalize(value) {
      return value ? value.charAt(0).toUpperCase() + value.slice(1) : value;
    },

    goMembers(status) {
      this.$router.push({ path: '/members', query: status ? { status } : {} });
    },

    goSessions(status) {
      this.$router.push({ path: '/attendance', query: status ? { status } : {} });
    },

    goFollowups() {
      this.$router.push({ path: '/history', query: { followup: '1' } });
    },

    goLatestSession() {
      if (this.latestSession) {
        this.openAttendance(this.latestSession);
      }
    },

    openAttendance(session) {
      this.$router.push({ path: '/attendance', query: { session: session.id } });
    },

    goMemberHistory(memberCode) {
      this.$router.push({ path: '/history', query: memberCode ? { member_code: memberCode } : {} });
    },
  },
};
</script>
