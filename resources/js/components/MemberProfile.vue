<template>
  <aside v-if="member" class="member-profile-panel">
    <div class="profile-card">
      <div class="profile-hero">
        <div class="profile-avatar is-external">
          <span class="avatar-initials">{{ initials(member) }}</span>
        </div>
        <div class="profile-hero-info">
          <h2 class="profile-name">{{ getFullName(member) }}</h2>
          <div class="profile-sub">{{ member.member_code }}</div>
          <div class="profile-status">
            <span class="status-tag" :class="statusClass(member.membership_status)">
              {{ member.membership_status }}
            </span>
          </div>
        </div>
      </div>

      <div class="profile-section">
        <div class="section-label">Contact</div>
        <div class="section-list">
          <div class="profile-field">
            <span class="profile-field-label">Email</span>
            <span class="profile-field-value">{{ member.email || '—' }}</span>
          </div>
          <div class="profile-field">
            <span class="profile-field-label">Mobile</span>
            <span class="profile-field-value">{{ member.mobile_number || '—' }}</span>
          </div>
        </div>
      </div>

      <div class="profile-section">
        <div class="section-label">Membership</div>
        <div class="section-list">
          <div class="profile-field">
            <span class="profile-field-label">Status</span>
            <span class="profile-field-value is-capitalize">{{ member.membership_status || '—' }}</span>
          </div>
          <div class="profile-field">
            <span class="profile-field-label">Date Joined</span>
            <span class="profile-field-value">{{ member.date_joined || '—' }}</span>
          </div>
        </div>
      </div>

      <div class="profile-section">
        <div class="section-label">Attendance</div>
        <div v-if="statsLoading" class="ministry-empty">Loading attendance...</div>
        <div v-else-if="stats" class="section-list">
          <div class="profile-field"><span class="profile-field-label">Present</span><span class="profile-field-value">{{ stats.present }} / {{ stats.total }}</span></div>
          <div class="profile-field"><span class="profile-field-label">Rate</span><span class="profile-field-value">{{ stats.rate }}%</span></div>
          <div class="profile-field"><span class="profile-field-label">Last attended</span><span class="profile-field-value">{{ stats.last_attendance_date || 'Never' }}</span></div>
        </div>
        <span v-else-if="statsError" class="ministry-empty">{{ statsError }}</span>
        <span v-else class="ministry-empty">Attendance stats are unavailable.</span>
      </div>

      <div class="profile-section">
        <div class="section-label">Ministries</div>
        <div class="ministry-tags">
          <span
            v-for="(ministry, i) in ministries"
            :key="i"
            class="ministry-tag"
          >{{ ministry }}</span>
          <span v-if="ministries.length === 0" class="ministry-empty">No ministries assigned.</span>
        </div>
      </div>
    </div>
  </aside>
</template>

<script>
import { getFullName, initials } from '../utils/format';

export default {
  name: 'MemberProfile',

  props: {
    member: {
      type: Object,
      default: () => ({}),
    },
    stats: {
      type: Object,
      default: null,
    },
    statsLoading: {
      type: Boolean,
      default: false,
    },
    statsError: {
      type: String,
      default: '',
    },
  },

  computed: {
    ministries() {
      const m = this.member.ministries;
      return Array.isArray(m) ? m : (m ? [m] : []);
    },
  },

  methods: {
    getFullName,
    initials,
    statusClass(status) {
      if (status === 'active') return 'status-active';
      if (status === 'inactive') return 'status-inactive';
      return '';
    },
  },
};
</script>
