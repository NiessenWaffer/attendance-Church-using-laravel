<template>
  <section class="attendance-page">
    <!-- Summary Strip -->
    <div class="stat-strip">
      <div class="stat-strip-item">
        <span class="stat-strip-label">Sessions</span>
        <span class="stat-strip-value">{{ sessions.length }}</span>
      </div>
      <div class="stat-strip-item">
        <span class="stat-strip-label">Unique Members</span>
        <span class="stat-strip-value">{{ summaryPresent }}</span>
      </div>
      <div class="stat-strip-item">
        <span class="stat-strip-label">Opportunities</span>
        <span class="stat-strip-value">{{ summaryOpportunities }}</span>
      </div>
      <div class="stat-strip-item stat-strip-item--emph">
        <span class="stat-strip-label">Audience rate</span>
        <span class="stat-strip-value">{{ summaryRate }}%</span>
      </div>
      <div class="stat-strip-live">
        <span class="stat-strip-live-dot" :class="{ 'is-refreshing': loading }"></span>
        <span>Updated {{ lastUpdated || '—' }}</span>
      </div>
    </div>

    <!-- Main Work Area -->
    <section class="work-area">
      <!-- Left Panel: Sessions Table -->
      <div class="table-panel">
        <!-- Toolbar -->
        <div class="toolbar">
          <b-input
            v-model="filters.search"
            placeholder="Search sessions..."
            icon="magnify"
            class="search-input"
            @input="debouncedLoadSessions"
          />
          <b-select v-model="filters.status" @input="loadSessions">
            <option value="">All Status</option>
            <option value="open">Open</option>
            <option value="closed">Closed</option>
          </b-select>
          <b-datepicker
            v-model="filters.date"
            placeholder="Filter by date"
            icon="calendar"
            class="att-date-filter"
            @input="loadSessions"
          />
        </div>

        <!-- Table Meta Counter -->
        <div class="table-meta">
          <span class="service-details-label">Service Details</span>
          <span>Showing <strong>{{ sessions.length }}</strong> session(s)</span>
          <span v-if="loading" class="loading-text">Loading...</span>
        </div>

        <!-- Sessions Table -->
        <div v-if="sessions.length" class="session-table-wrap">
          <table class="session-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Session / time</th>
                <th class="is-numeric">Participants</th>
                <th class="is-numeric">Expected</th>
                <th class="is-numeric">Other</th>
                <th class="is-numeric">Rate</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="session in sessions"
                :key="session.id"
                class="session-row"
                :class="{ 'is-selected': selectedSession && selectedSession.id === session.id }"
                tabindex="0"
                @click="onSelectSession(session)"
                @keydown.enter="onSelectSession(session)"
                @keydown.space.prevent="onSelectSession(session)"
              >
                <td class="session-date">{{ session.session_date }}</td>
                <td>
                  <span class="session-main">
                    <strong>{{ session.session_title }}</strong>
                    <small>{{ formatTime12(session.start_time) || 'Time not set' }} · {{ session.session_type || 'Service' }}</small>
                  </span>
                </td>
                <td class="is-numeric">{{ session.participation_count || 0 }}</td>
                <td class="is-numeric session-expected">{{ session.expected_present_count || 0 }} / {{ session.eligible_member_count || 0 }}</td>
                <td class="is-numeric">{{ session.guest_other_count || 0 }}</td>
                <td class="is-numeric">{{ attendanceRate(session) }}%</td>
                <td><span class="status-tag" :class="session.is_closed ? 'status-inactive' : 'status-active'">{{ session.is_closed ? 'Closed' : 'Open' }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Empty State -->
        <div v-else class="empty-state">
          <p class="empty-title">No sessions</p>
          <p class="empty-desc">
            No generated attendance sessions available. Create or activate a schedule, then generate a session from the Schedules page.
          </p>
        </div>
      </div>

       <!-- Right Panel: selected session attendance -->
       <aside class="side-panel">
         <div v-if="sessionData" class="detail-container">
           <div class="panel-header">
             <span class="panel-eyebrow">Session Attendance</span>
             <button class="close-btn" type="button" @click="clearSelection">✕</button>
           </div>

           <div class="profile-header">
             <h2 class="profile-name">{{ sessionData.session.session_title }}</h2>
             <span class="att-count-badge">{{ recordCount }} {{ recordCount === 1 ? 'participant' : 'participants' }}</span>
          </div>

          <div class="detail-list">
            <div class="detail-row">
              <span class="detail-label">Date</span>
              <span class="detail-val">{{ sessionData.session.session_date }}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Time</span>
              <span class="detail-val">{{ formatTime12(sessionData.session.start_time) || '-' }}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Status</span>
              <span class="detail-val">{{ formatSessionStatus(sessionData.session.session_status) }}<template v-if="sessionData.session.is_closed"> · Closed</template></span>
            </div>
            <div class="detail-row full-width" v-if="sessionData.session.remarks">
              <span class="detail-label">Remarks</span>
              <p class="notes-text">{{ sessionData.session.remarks }}</p>
            </div>
          </div>

           <div class="attendance-detail-actions">
             <button v-if="sessionData.session.session_status === 'scheduled'" class="button is-dark" type="button" :disabled="statusSaving || attendanceLoading" @click="updateSessionStatus('active')">Start Session</button>
             <button v-if="sessionData.session.session_status !== 'completed' && sessionData.session.session_status !== 'cancelled'" class="button is-dark" type="button" :disabled="statusSaving || attendanceLoading" @click="updateSessionStatus('completed')">Complete Session</button>
             <button v-if="sessionData.session.session_status !== 'cancelled'" class="button" type="button" :disabled="statusSaving || attendanceLoading" @click="updateSessionStatus('cancelled')">Cancel Session</button>
             <button v-if="sessionData.session.session_status === 'completed' || sessionData.session.session_status === 'cancelled'" class="button" type="button" :disabled="statusSaving || attendanceLoading" @click="updateSessionStatus('scheduled')">Reopen Session</button>
           </div>

           <div class="manual-attendance">
             <b-select v-model="manualMember" size="is-small" expanded>
               <option value="">Select member to add</option>
               <option v-for="member in sessionData.members" :key="member.member_code || member.external_id" :value="member.member_code || member.external_id">
                 {{ getFullName(member) }}
               </option>
             </b-select>
             <button class="button is-small" type="button" :disabled="!manualMember || manualSaving" @click="addManualAttendance">
               {{ manualSaving ? 'Adding...' : 'Add Attendance' }}
             </button>
           </div>

            <p class="roster-title">Participants</p>
           <div class="roster-toolbar">
             <b-input
               v-model="rosterSearch"
               size="is-small"
               icon="magnify"
               placeholder="Search roster..."
               class="roster-search"
             />
             <b-select v-model="rosterSort" size="is-small" class="roster-sort">
               <option value="newest">Newest</option>
               <option value="oldest">Oldest</option>
             </b-select>
             <b-button size="is-small" type="is-light" :disabled="!recordRows.length" @click="exportRoster">Export</b-button>
           </div>
           <div class="roster-list">
             <div v-if="filteredRecordRows.length" class="roster-head" aria-hidden="true">
               <span>Name / code</span>
               <span>Check-in</span>
               <span></span>
             </div>
             <div v-for="row in filteredRecordRows" :key="row.id" class="roster-row">
               <span class="roster-name">
                <button
                  class="roster-name-btn"
                  type="button"
                  :disabled="!row.external_member_id && !row.member_code"
                  :title="row.external_member_id ? 'Open member profile' : ''"
                  @click="goToMember(row)"
                 >{{ row.name }}</button>
                 <small class="roster-code">{{ row.external_member_id || row.member_code || 'No code' }}</small>
               </span>
               <time class="roster-checkin">{{ row.created_at || '-' }}</time>
               <button class="record-remove" type="button" :disabled="removingRecordId !== null || attendanceLoading" @click.stop="removeRecord(row)">
                 {{ removingRecordId === row.id ? 'Removing...' : 'Remove' }}
               </button>
             </div>
             <div v-if="!recordRows.length" class="roster-empty">
               No attendance records for this session.
            </div>
            <div v-else-if="!filteredRecordRows.length" class="roster-empty">
              No records match "{{ rosterSearch }}".
            </div>
          </div>
        </div>

        <!-- Blank State -->
         <div v-else-if="attendanceLoading" class="blank-panel">
            <p class="blank-title">Loading Session</p>
            <p class="blank-desc">Loading attendance...</p>
          </div>
          <div v-else class="blank-panel">
           <p class="blank-title">No Session Selected</p>
           <p class="blank-desc">Select a session to view attendance.</p>
        </div>
      </aside>
    </section>

  </section>
</template>

<script>
import _ from 'lodash';
import { api } from '../services/api';
import { formatDate, formatTime12, getFullName } from '../utils/format';

export default {
  name: 'AttendancePage',

  data() {
    return {
      filters: {
        search: '',
        status: '',
        date: null,
      },
      sessions: [],
      selectedSession: null,
      sessionData: null,
      recordRows: [],
      loading: false,
      attendanceLoading: false,
      statusSaving: false,
      removingRecordId: null,
      manualMember: '',
      manualSaving: false,
      rosterSearch: '',
      rosterSort: 'newest',
      lastUpdated: '',
      summaryUniqueMembers: null,
      sessionsRequestSeq: 0,
      attendanceRequestSeq: 0,
    };
  },

  computed: {
    recordCount() { return this.recordRows.length; },

    summaryPresent() {
      return this.summaryUniqueMembers === null ? 0 : this.summaryUniqueMembers;
    },

    summaryOpportunities() {
      return this.sessions.reduce((sum, s) => sum + (Number(s.eligible_member_count) || 0), 0);
    },

    summaryRate() {
      const expected = this.sessions.reduce((sum, s) => sum + (Number(s.expected_present_count) || 0), 0);
      return this.summaryOpportunities > 0 ? Math.round((expected / this.summaryOpportunities) * 100) : 0;
    },

    filteredRecordRows() {
      const query = this.rosterSearch.trim().toLowerCase();
      let rows = this.recordRows;
      if (query) {
        rows = rows.filter((row) => {
          const name = String(row.name || '').toLowerCase();
          const code = String(row.external_member_id || row.member_code || '').toLowerCase();
          return name.includes(query) || code.includes(query);
        });
      }
      const dir = this.rosterSort === 'oldest' ? 1 : -1;
      return rows.slice().sort((a, b) => {
        const ta = Date.parse(a.created_at) || 0;
        const tb = Date.parse(b.created_at) || 0;
        return ta === tb ? 0 : (ta - tb) * dir;
      });
    },
  },

  created() {
    this.debouncedLoadSessions = _.debounce(this.loadSessions, 300);
  },

  mounted() {
    this.applyRouteFilters();
    this.loadSessions().then(() => {
      const sessionId = this.$route.query.session;
      if (sessionId) {
        const session = this.sessions.find(s => Number(s.id) === Number(sessionId));
        if (session) {
          this.onSelectSession(session);
        }
      }
    });
    this._refreshTimer = setInterval(this.refreshLive, 30000);
  },

  beforeDestroy() {
    this.sessionsRequestSeq += 1;
    this.attendanceRequestSeq += 1;
    if (this.debouncedLoadSessions && this.debouncedLoadSessions.cancel) {
      this.debouncedLoadSessions.cancel();
    }
    if (this._refreshTimer) {
      clearInterval(this._refreshTimer);
      this._refreshTimer = null;
    }
  },

  watch: {
    '$route.query.status'(value) {
      if (value !== undefined && value !== null) {
        this.filters.status = value;
        this.loadSessions();
      }
    },
  },

  methods: {
    getFullName,
    formatDate,
    formatTime12,

    formatSessionStatus(status) {
      if (!status) return '—';
      return status.charAt(0).toUpperCase() + status.slice(1);
    },

    attendanceRate(session) {
      return Number(session.attendance_rate) || 0;
    },

    refreshLive() {
      const sessionId = this.selectedSession ? this.selectedSession.id : null;
      this.loadSessions();
      if (sessionId && !this.manualMember && !this.manualSaving && !this.statusSaving && this.removingRecordId === null) {
        this.loadSessionAttendance(sessionId);
      }
    },

    goToMember(row) {
      const code = row.external_member_id || row.member_code;
      if (!code) return;
      this.$router.push({ path: '/members', query: { search: code } });
    },

    exportRoster() {
      const rows = this.filteredRecordRows;
      if (!rows.length) {
        this.$buefy.toast.open({ message: 'No records to export.', type: 'is-warning' });
        return;
      }
      const csv = [
        ['Name', 'Code', 'Checked In'],
        ...rows.map((row) => [
          row.name || '',
          row.external_member_id || row.member_code || '',
          row.created_at || '',
        ]),
      ]
        .map((cols) => cols.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(','))
        .join('\r\n');

      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const session = this.sessionData && this.sessionData.session ? this.sessionData.session : {};
      const title = String(session.session_title || 'attendance').replace(/[^a-z0-9]+/gi, '-');
      const a = document.createElement('a');
      a.href = url;
      a.download = `${title}-${session.session_date || ''}.csv`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    },

    applyRouteFilters() {
      const status = this.$route.query.status;
      if (status !== undefined && status !== null) {
        this.filters.status = status;
      }
    },

    async loadSessions() {
      const requestId = ++this.sessionsRequestSeq;
      this.loading = true;
      try {
        const params = {};
        if (this.filters.search) {
          params.search = this.filters.search;
        }
        if (this.filters.status) {
          params.status = this.filters.status;
        }
        if (this.filters.date) {
          const date = this.formatDate(this.filters.date);
          params.date_from = date;
          params.date_to = date;
        }

        const payload = await api.get('/api/attendance-sessions', params);
        if (requestId !== this.sessionsRequestSeq) return;
        this.sessions = payload && Array.isArray(payload.data) ? payload.data : [];
        this.summaryUniqueMembers = this.backendUniqueMemberCount(payload, this.sessions);
        if (this.summaryUniqueMembers === null) {
          this.loadSummaryUniqueMembers(this.sessions, requestId);
        }

        if (this.selectedSession) {
          const stillThere = this.sessions.find(s => Number(s.id) === Number(this.selectedSession.id));
          if (!stillThere) {
            this.clearSelection();
          }
        }
      } catch (error) {
        if (requestId !== this.sessionsRequestSeq) return;
        console.error('Failed to load sessions:', error);
      } finally {
        if (requestId === this.sessionsRequestSeq) {
          this.loading = false;
          this.lastUpdated = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
        }
      }
    },

    backendUniqueMemberCount(payload, sessions) {
      const data = payload && payload.data;
      const candidates = [
        payload && payload.unique_member_count,
        payload && payload.unique_members,
        payload && payload.unique_daily_members,
        payload && payload.unique_attendee_days,
        data && !Array.isArray(data) ? data.unique_member_count : null,
        data && !Array.isArray(data) ? data.unique_members : null,
        data && !Array.isArray(data) ? data.unique_daily_members : null,
        data && !Array.isArray(data) ? data.unique_attendee_days : null,
      ];
      const count = candidates.find(value => value !== null && value !== undefined && value !== '');
      if (count !== undefined) return Number(count) || 0;

      const sessionCount = sessions.find(session => session.unique_member_count !== undefined);
      return sessionCount && sessions.length === 1 ? Number(sessionCount.unique_member_count) || 0 : null;
    },

    async loadSummaryUniqueMembers(sessions, requestId) {
      if (!sessions.length) {
        this.summaryUniqueMembers = 0;
        return;
      }

      try {
        const payloads = await Promise.all(sessions.map(session => api.get(`/api/attendance-sessions/${session.id}/attendance`)));
        if (requestId !== this.sessionsRequestSeq) return;

        const identifiers = new Set();
        payloads.forEach((payload, index) => {
          const sessionDate = payload && payload.data && payload.data.session
            ? payload.data.session.session_date
            : sessions[index].session_date;
          const records = payload && payload.data && Array.isArray(payload.data.records)
            ? payload.data.records
            : [];
          records.forEach(record => {
            const identifier = record.external_member_id || record.member_code || record.member_id;
            if (identifier !== undefined && identifier !== null && String(identifier) !== '') {
              identifiers.add(`${sessionDate || 'unknown-date'}|${String(identifier)}`);
            }
          });
        });
        this.summaryUniqueMembers = identifiers.size;
      } catch (error) {
        if (requestId === this.sessionsRequestSeq) {
          this.summaryUniqueMembers = null;
          console.error('Failed to calculate unique attendance members:', error);
        }
      }
    },

    async onSelectSession(row) {
      this.selectedSession = row;
      this.sessionData = null;
      this.recordRows = [];
      this.manualMember = '';
      await this.loadSessionAttendance(row.id);
    },

    async loadSessionAttendance(sessionId) {
      const requestId = ++this.attendanceRequestSeq;
      this.attendanceLoading = true;
      try {
        const payload = await api.get(`/api/attendance-sessions/${sessionId}/attendance`);
        if (requestId !== this.attendanceRequestSeq
          || !this.selectedSession
          || Number(this.selectedSession.id) !== Number(sessionId)) return;
        this.sessionData = payload.data;
        this.manualMember = '';
        this.buildRecordRows();
      } catch (error) {
        if (requestId !== this.attendanceRequestSeq) return;
        console.error('Failed to load attendance:', error);
        this.sessionData = null;
        this.recordRows = [];
      } finally {
        if (requestId === this.attendanceRequestSeq) this.attendanceLoading = false;
      }
    },

    async updateSessionStatus(status) {
      if (this.statusSaving || !this.sessionData || !this.sessionData.session) return;
      const sessionId = this.sessionData.session.id;
      this.statusSaving = true;
      try {
        const payload = await api.patch(`/api/attendance-sessions/${sessionId}/status`, { status });
        this.$buefy.toast.open({ message: payload.message || 'Session updated.', type: 'is-success' });
        await this.loadSessions();
        if (this.selectedSession && Number(this.selectedSession.id) === Number(sessionId)) {
          const session = this.sessions.find(item => Number(item.id) === Number(sessionId));
          if (session) await this.loadSessionAttendance(sessionId);
        }
      } catch (error) {
        this.$buefy.toast.open({ message: 'Could not update the session.', type: 'is-danger' });
      } finally {
        this.statusSaving = false;
      }
    },

    async addManualAttendance() {
      if (this.manualSaving || !this.sessionData || !this.manualMember) return;
      const sessionId = this.sessionData.session.id;
      const memberId = this.manualMember;
      this.manualSaving = true;
      try {
        const payload = await api.post('/api/attendance-records', {
          session_id: sessionId,
          external_member_id: memberId,
        });
        this.$buefy.toast.open({ message: payload.message || 'Attendance recorded.', type: 'is-success' });
        await this.loadSessions();
        if (this.selectedSession && Number(this.selectedSession.id) === Number(sessionId)) {
          await this.loadSessionAttendance(sessionId);
        }
      } catch (error) {
        this.$buefy.toast.open({ message: error.response && error.response.data ? error.response.data.message : 'Could not add attendance.', type: 'is-danger' });
      } finally {
        this.manualSaving = false;
      }
    },

    removeRecord(row) {
      if (this.removingRecordId !== null || !this.sessionData || !this.sessionData.session) return;
      const recordId = row.id;
      const sessionId = this.sessionData.session.id;
      this.$buefy.dialog.confirm({
        title: 'Remove attendance record',
        message: 'Remove this check-in? This action is recorded in the audit log.',
        type: 'is-danger',
        confirmText: 'Remove',
        onConfirm: async () => {
          if (this.removingRecordId !== null) return;
          this.removingRecordId = recordId;
          try {
            const payload = await api.delete(`/api/attendance-records/${recordId}`);
            this.$buefy.toast.open({ message: payload.message || 'Record removed.', type: 'is-success' });
            await this.loadSessions();
            if (this.selectedSession && Number(this.selectedSession.id) === Number(sessionId)) {
              await this.loadSessionAttendance(sessionId);
            }
          } catch (error) {
            this.$buefy.toast.open({ message: 'Could not remove the record.', type: 'is-danger' });
          } finally {
            this.removingRecordId = null;
          }
        },
      });
    },

    buildRecordRows() {
      if (!this.sessionData) {
        this.recordRows = [];
        return;
      }

      const records = this.sessionData.records || [];
      const members = this.sessionData.members || [];
      this.recordRows = records.map((record) => {
        const externalId = record.external_member_id || record.member_code || record.member_id || '';
        const member = members.find((item) => String(item.member_code || '') === String(externalId) || String(item.external_id || '') === String(externalId));
        return {
          ...record,
          name: record.member_name_cache || (member ? this.getFullName(member) : (externalId || 'Unknown member')),
        };
      });
    },


    clearSelection() {
      this.attendanceRequestSeq += 1;
      this.selectedSession = null;
      this.sessionData = null;
      this.recordRows = [];
      this.attendanceLoading = false;
      this.manualMember = '';
    },
  },
};
</script>
