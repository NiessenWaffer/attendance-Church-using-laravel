<template>
  <app-page>
    <section class="members-workspace">
      <div class="toolbar members-toolbar" v-if="externalMembers.length || loading">
        <b-input
          v-model="filters.search"
          type="text"
          size="is-small"
          placeholder="Search members..."
          icon="magnify"
          class="members-search"
          @input="onSearchInput"
        />

        <b-select
          v-model="filters.status"
          size="is-small"
          class="members-status"
          @input="loadFromQuery"
        >
          <option value="">All Statuses</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </b-select>

        <div class="members-toolbar-actions">
          <b-button type="is-dark" size="is-small" :loading="loading" @click="loadExternalMembers(true)">
            Refresh
          </b-button>
          <b-button type="is-light" size="is-small" @click="openFollowups">
            Needs Follow-Up
          </b-button>
        </div>
      </div>

      <div v-if="viewData.length" class="members-split">
        <div class="members-list-pane member-table-wrap">
          <b-table
            :data="pagedData"
            hoverable
            :loading="loading"
            :selected="selectedMember"
            class="page-table"
            @click="setSelected($event)"
          >
            <b-table-column label="Code" field="member_code" width="130" sortable>
              <template v-slot="props">
                <code class="member-code">{{ props.row.member_code }}</code>
              </template>
            </b-table-column>

            <b-table-column label="Name" field="last_name" sortable>
              <template v-slot="props">
                <span class="cell-title">{{ getFullName(props.row) }}</span>
              </template>
            </b-table-column>

            <b-table-column label="Email" field="email" sortable>
              <template v-slot="props">{{ props.row.email || '' }}</template>
            </b-table-column>

            <b-table-column label="Mobile" field="mobile_number" sortable>
              <template v-slot="props">{{ props.row.mobile_number || '' }}</template>
            </b-table-column>

            <b-table-column label="Status" field="membership_status" width="120" sortable>
              <template v-slot="props">
                <span class="status-tag" :class="statusClass(props.row.membership_status)">
                  {{ props.row.membership_status }}
                </span>
              </template>
            </b-table-column>

          </b-table>

          <div class="members-footer" v-if="viewData.length">
            <div class="members-footer-meta">
              <span>Showing <strong>{{ pagedData.length }}</strong> of <strong>{{ viewData.length }}</strong> member(s)</span>
              <span v-if="loading" class="loading-text">Loading...</span>
            </div>

            <b-pagination
              v-if="totalPages > 1"
              v-model="currentPage"
              :total="viewData.length"
              :per-page="perPage"
              size="is-small"
              order="is-centered"
            />
          </div>
        </div>

        <member-profile
          v-if="selectedMember"
          :member="selectedMember"
          :stats="selectedMemberStats"
          :stats-loading="memberStatsLoading"
          :stats-error="memberStatsError"
        />
        <aside v-else class="member-profile-placeholder">
          <p class="empty-title">Select a member</p>
          <p class="empty-desc">Click any member row to view the complete profile.</p>
        </aside>
      </div>

      <div v-else-if="!loading" class="empty-state">
        <p class="empty-title">No members found</p>
        <p class="empty-desc">Adjust the search/status filters or refresh.</p>
      </div>
    </section>
  </app-page>
</template>

<script>
import { api, errorMessage } from '../services/api';
import { getFullName } from '../utils/format';
import MemberProfile from '../components/MemberProfile.vue';

export default {
  name: 'MembersPage',

  components: {
    MemberProfile,
  },

  data() {
      return {
        externalMembers: [],
        selectedMember: null,
        selectedMemberStats: null,
        memberStatsLoading: false,
        memberStatsError: '',
        filters: { search: '', status: '' },
        currentPage: 1,
        perPage: 20,
        loading: false,
        searchTimer: null,
        memberStatsRequestSeq: 0,
      };
  },

  computed: {
    viewData() {
      let rows = this.externalMembers;

      const search = (this.filters.search || '').toLowerCase().trim();

      if (search) {
        rows = rows.filter((m) => {
          const fields = [
            m.member_code,
            m.first_name,
            m.last_name,
            m.email,
            m.mobile_number,
          ].join(' ').toLowerCase();

          return fields.includes(search);
        });
      }

      if (this.filters.status) {
        rows = rows.filter((m) => m.membership_status === this.filters.status);
      }

      return rows.sort((a, b) => {
        const aName = `${a.last_name || ''}, ${a.first_name || ''}`.toLowerCase();
        const bName = `${b.last_name || ''}, ${b.first_name || ''}`.toLowerCase();

        return aName < bName ? -1 : aName > bName ? 1 : 0;
      });
    },

    pagedData() {
      const start = (this.currentPage - 1) * this.perPage;
      return this.viewData.slice(start, start + this.perPage);
    },

    totalPages() {
      return Math.ceil(this.viewData.length / this.perPage);
    },
  },

  created() {
    this.initFromRoute();
    this.loadExternalMembers();
  },

  watch: {
    viewData(rows) {
      if (this.currentPage > this.totalPages) {
        this.currentPage = this.totalPages || 1;
      }

      if (!rows.length) {
        this.selectedMember = null;
        return;
      }

      if (!this.selectedMember || !rows.find((row) => row.member_code === this.selectedMember.member_code)) {
        this.selectedMember = rows[0];
      }
    },
    selectedMember(member) {
      this.loadMemberStats(member);
    },
  },

  methods: {
    getFullName,
    initFromRoute() {
      const query = this.$route.query || {};
      this.filters.status = query.status || '';
      this.filters.search = query.search || '';
    },

    onSearchInput() {
      clearTimeout(this.searchTimer);
      this.searchTimer = setTimeout(() => {
        this.pushFilters();
      }, 300);
    },

    pushFilters() {
      this.currentPage = 1;
      const query = {};
      if (this.filters.status) query.status = this.filters.status;
      if (this.filters.search) query.search = this.filters.search;
      this.$router.replace({ path: '/members', query });
    },

    loadFromQuery() {
      this.pushFilters();
    },

    async loadExternalMembers(refresh = false) {
      this.loading = true;
      try {
        const payload = await api.get('/api/integration/fetch', refresh ? { refresh: 1 } : {});
        this.externalMembers = payload.data.members || [];
        this.selectedMember = this.externalMembers[0] || null;
      } catch (e) {
        this.$buefy.toast.open({
          message: errorMessage(e, 'Could not load members.'),
          type: 'is-danger',
        });
      } finally {
        this.loading = false;
      }
    },

    async loadMemberStats(member) {
      const requestId = ++this.memberStatsRequestSeq;
      this.selectedMemberStats = null;
      this.memberStatsError = '';
      this.memberStatsLoading = Boolean(member && member.member_code);
      if (!member || !member.member_code) return;
      const memberCode = member.member_code;
      try {
        const payload = await api.get(`/api/attendance-records/member/${encodeURIComponent(memberCode)}/stats`);
        if (requestId !== this.memberStatsRequestSeq
          || !this.selectedMember
          || String(this.selectedMember.member_code) !== String(memberCode)) return;
        this.selectedMemberStats = payload.data || null;
      } catch (e) {
        if (requestId !== this.memberStatsRequestSeq) return;
        this.selectedMemberStats = null;
        this.memberStatsError = errorMessage(e, 'Could not load attendance stats.');
      } finally {
        if (requestId === this.memberStatsRequestSeq) {
          this.memberStatsLoading = false;
        }
      }
    },

    setSelected(member) {
      this.selectedMember = member;
    },

    openFollowups() {
      this.$router.push({ path: '/history', query: { followup: '1' } });
    },

    statusClass(status) {
      if (status === 'active') return 'status-active';
      if (status === 'inactive') return 'status-inactive';
      return '';
    },
  },
};
</script>

<style scoped>
.members-workspace {
  display: flex;
  flex: 1;
  min-height: 0;
  flex-direction: column;
  overflow: hidden;
  background: #fff;
  border: 1px solid #dfe3e8;
}

.members-toolbar {
  align-items: center;
  min-height: 44px;
  margin: 0;
  padding: 7px 10px;
  border-bottom: 1px solid #dfe3e8;
}

.members-toolbar-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

.members-split {
  display: grid;
  grid-template-columns: minmax(0, 1.8fr) minmax(280px, .8fr);
  flex: 1;
  min-height: 0;
}

.members-list-pane {
  display: flex;
  min-width: 0;
  min-height: 0;
  flex-direction: column;
  padding: 0 8px 8px;
  border-right: 1px solid #dfe3e8;
}

.member-profile-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  text-align: center;
}

.members-workspace ::v-deep .b-table {
  flex: 1;
  min-height: 0;
}

.members-workspace ::v-deep .table th {
  height: 34px;
  color: #59636e;
  background: #f7f8f9;
  border-bottom: 1px solid #dfe3e8;
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
}

.members-workspace ::v-deep .table td {
  height: 38px;
  padding-top: 6px;
  padding-bottom: 6px;
  border-bottom: 1px solid #eef0f2;
  font-size: 12px;
}

.members-workspace ::v-deep .table tr:hover td {
  background: #f5f7f9;
}

.members-workspace ::v-deep .table tr.is-selected td {
  background: #e5e7eb !important;
  color: #111827 !important;
}

.members-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 0;
  padding: 8px 2px 0;
  border-top: 1px solid #eef0f2;
}

.members-footer-meta {
  font-size: 12px;
  color: #6b7280;
}

.members-search {
  flex: 1;
  min-width: 0;
  max-width: 320px;
}

.members-status {
  min-width: 160px;
}

@media (max-width: 900px) {
  .members-workspace { overflow: auto; }
  .members-split { grid-template-columns: 1fr; min-height: 700px; }
  .members-list-pane { border-right: 0; border-bottom: 1px solid #dfe3e8; }
}

@media (max-width: 768px) {
  .members-workspace {
    overflow: visible;
    border: 0;
    background: transparent;
  }

  .members-toolbar {
    min-height: 0;
    padding: 0 0 7px;
  }

  .members-split {
    min-height: 0;
  }

  .members-list-pane {
    padding: 0;
    border-bottom: 0;
  }

  .members-workspace ::v-deep .table th {
    height: 28px;
    padding: 5px 7px;
    font-size: 8px;
  }

  .members-workspace ::v-deep .table td {
    height: 30px;
    padding: 5px 7px;
    font-size: 10px;
  }

  .members-footer {
    gap: 6px;
    padding-top: 5px;
  }

  .members-footer-meta {
    font-size: 9px;
    white-space: nowrap;
  }

  .members-status {
    min-width: 120px;
  }
}

@media (max-width: 440px) {
  .members-footer {
    align-items: flex-start;
    flex-direction: column;
  }

  .members-footer ::v-deep .pagination {
    width: 100%;
    justify-content: flex-start;
  }
}
</style>
