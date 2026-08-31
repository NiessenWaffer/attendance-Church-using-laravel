<template>
  <app-page>
    <app-filter-bar>
      <b-input v-model="filters.search" placeholder="Search service names..." icon="magnify" size="is-small" class="search-input" @input="loadSchedules" />
      <b-select v-model="filters.status" size="is-small" @input="loadSchedules">
        <option value="">All Status</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </b-select>
      <b-select v-model="filters.service_type" size="is-small" @input="loadSchedules">
        <option value="">All Service Types</option>
        <option v-for="type in serviceTypeOptions" :key="type.value" :value="type.value">{{ type.label }}</option>
      </b-select>
      <b-select v-model="filters.origin" size="is-small" @input="loadSchedules">
        <option value="">All Sources</option>
        <option value="default">System-provided</option>
        <option value="custom">Created by an admin</option>
      </b-select>
      <template #actions>
        <b-button type="is-light" size="is-small" @click="rangeOpen = true">Create Dated Services</b-button>
        <b-button type="is-dark" size="is-small" @click="openCreate">Add Service Schedule</b-button>
      </template>
    </app-filter-bar>

    <section class="work-area">
      <app-table-panel>
        <template #meta>
          <span>Showing <strong>{{ schedules.length }}</strong> service schedule(s)</span>
          <span v-if="loading" class="loading-text">Loading...</span>
        </template>

        <div v-if="schedules.length" class="member-table-wrap schedule-table-wrap">
          <b-table
            :data="schedules"
            :loading="loading"
            :row-class="scheduleRowClass"
            hoverable
            class="page-table schedule-table"
            @click="selectSchedule"
          >
            <b-table-column field="name" label="Name / Description" v-slot="props">
              <span class="cell-title">{{ props.row.name }}</span>
              <span class="cell-sub schedule-description">{{ props.row.description || 'Recurring attendance service' }}</span>
            </b-table-column>

            <b-table-column field="schedule_day" label="When It Repeats" width="130" v-slot="props">
              <span class="cell-title">{{ scheduleDayLabel(props.row) }}</span>
              <span v-if="recurrenceDetail(props.row)" class="cell-sub">{{ recurrenceDetail(props.row) }}</span>
            </b-table-column>

            <b-table-column field="start_time" label="Time" width="160" v-slot="props">
              <span class="text-neutral schedule-time">{{ timeLabel(props.row) }}</span>
            </b-table-column>

            <b-table-column field="service_type" label="Type / Eligible Ministries" width="190" v-slot="props">
              <span class="cell-title schedule-category">{{ props.row.service_type || props.row.session_type || '-' }}</span>
              <span class="cell-sub">{{ ministryLabel(props.row) }}</span>
            </b-table-column>

            <b-table-column field="is_active" label="Status" width="90" v-slot="props">
              <span class="status-tag" :class="props.row.is_active ? 'status-active' : 'status-inactive'">
                {{ props.row.is_active ? 'Active' : 'Inactive' }}
              </span>
            </b-table-column>

            <b-table-column label="Actions" width="72" centered v-slot="props">
              <b-dropdown position="is-bottom-left" aria-role="menu" append-to-body @click.native.stop>
                <template #trigger>
                  <b-button type="is-light" size="is-small" icon-left="dots-horizontal" aria-label="Service actions" />
                </template>
                <b-dropdown-item aria-role="menuitem" @click="openEdit(props.row)">Edit</b-dropdown-item>
                <b-dropdown-item aria-role="menuitem" @click="toggleSchedule(props.row)">{{ props.row.is_active ? 'Deactivate' : 'Activate' }}</b-dropdown-item>
                <b-dropdown-item v-if="!props.row.is_default" aria-role="menuitem" class="has-text-danger" @click="confirmDelete(props.row)">Delete</b-dropdown-item>
              </b-dropdown>
            </b-table-column>
          </b-table>
        </div>
        <div v-else-if="!loading" class="empty-state">
          <p class="empty-title">No services</p>
          <p class="empty-desc">Create a recurring or one-time service before generating attendance sessions.</p>
        </div>
      </app-table-panel>

      <aside class="side-panel">
        <div v-if="selectedSchedule" class="detail-container">
          <div class="panel-header"><span class="panel-eyebrow">Reusable Service Schedule</span></div>
          <div class="profile-header">
            <h2 class="profile-name">{{ selectedSchedule.name }}</h2>
            <span class="status-tag" :class="selectedSchedule.is_active ? 'status-active' : 'status-inactive'">
              {{ selectedSchedule.is_active ? 'Active' : 'Inactive' }}
            </span>
          </div>
          <div class="detail-list">
            <div class="detail-row"><span class="detail-label">Description</span><span class="detail-val">{{ selectedSchedule.description || '-' }}</span></div>
            <div class="detail-row"><span class="detail-label">Repeats On</span><span class="detail-val">{{ scheduleDayLabel(selectedSchedule) }}</span></div>
            <div class="detail-row" v-if="selectedSchedule.schedule_day === 'One-time'"><span class="detail-label">Service Date</span><span class="detail-val">{{ selectedSchedule.specific_date || '-' }}</span></div>
            <div class="detail-row"><span class="detail-label">Service Time</span><span class="detail-val">{{ timeLabel(selectedSchedule) }}</span></div>
            <div class="detail-row"><span class="detail-label">Service Type</span><span class="detail-val">{{ selectedSchedule.service_type || selectedSchedule.session_type || '-' }}</span></div>
            <div class="detail-row"><span class="detail-label">Eligible Ministries</span><span class="detail-val">{{ (selectedSchedule.ministries || []).join(', ') || 'All active members' }}</span></div>
          </div>
          <p class="schedule-help">
             This schedule does not hold attendance. Creating a date produces a dated service, where attendance is recorded.
          </p>
          <div class="att-save-row">
            <button class="button" type="button" @click="openEdit(selectedSchedule)">Edit Service</button>
            <button class="button is-dark" type="button" :disabled="generating || !selectedScheduleActive" @click="generateToday">
              {{ generating ? 'Creating...' : "Create Today's Service" }}
            </button>
          </div>
        </div>
        <div v-else class="blank-panel">
          <p class="blank-title">No Service Selected</p>
          <p class="blank-desc">Select a service to view its schedule and generate today's session.</p>
        </div>
      </aside>
    </section>

    <b-modal v-model="formOpen" :width="620" scroll="clip">
      <div class="schedule-modal">
        <div class="drawer-head">
          <div><p class="eyebrow">Service Template</p><h2 class="drawer-title">{{ editingSchedule ? 'Edit Service' : 'Add Service' }}</h2></div>
          <button class="drawer-close" type="button" @click="formOpen = false">✕</button>
        </div>
        <form class="form-grid" @submit.prevent="saveSchedule">
          <div class="form-section-title">Service</div>
          <b-field label="Service Name" class="span-2"><b-input v-model="form.name" :disabled="editingBuiltIn" required /></b-field>
          <b-field label="Short Description" class="span-2"><b-input v-model="form.description" /></b-field>

          <div class="form-section-title">Schedule</div>
          <b-field label="Schedule Day">
            <b-select v-model="form.schedule_day" :disabled="editingBuiltIn" expanded>
              <option v-for="day in scheduleDayOptions" :key="day" :value="day">{{ day }}</option>
            </b-select>
          </b-field>
          <b-field v-if="form.schedule_day === 'One-time'" label="Specific Date">
            <b-input v-model="form.specific_date" type="date" required />
          </b-field>
          <b-field v-else-if="form.schedule_day === 'Monthly'" label="Day of Month">
            <b-input v-model.number="form.day_of_month" type="number" min="1" max="31" required />
          </b-field>
          <b-field label="Service Category">
            <b-select v-model="form.service_type" :disabled="editingBuiltIn" expanded>
              <option v-for="type in formServiceTypeOptions" :key="type.value" :value="type.value">{{ type.label }}</option>
            </b-select>
          </b-field>

          <b-field label="Schedule Time" class="span-2">
            <div class="time-range">
              <div class="time-group">
                <span class="time-group-label">Start</span>
                <div class="time-picker">
                  <b-select v-model="form.start.hour" expanded required>
                    <option v-for="h in hourOptions" :key="h" :value="h">{{ h }}</option>
                  </b-select>
                  <span class="time-colon">:</span>
                  <b-select v-model="form.start.minute" expanded required>
                    <option v-for="m in minuteOptions" :key="m" :value="m">{{ m }}</option>
                  </b-select>
                  <b-select v-model="form.start.period" expanded required>
                    <option value="AM">AM</option>
                    <option value="PM">PM</option>
                  </b-select>
                </div>
              </div>
              <div class="time-group">
                <span class="time-group-label">End</span>
                <div class="time-picker">
                  <b-select v-model="form.end.hour" expanded required>
                    <option v-for="h in hourOptions" :key="h" :value="h">{{ h }}</option>
                  </b-select>
                  <span class="time-colon">:</span>
                  <b-select v-model="form.end.minute" expanded required>
                    <option v-for="m in minuteOptions" :key="m" :value="m">{{ m }}</option>
                  </b-select>
                  <b-select v-model="form.end.period" expanded required>
                    <option value="AM">AM</option>
                    <option value="PM">PM</option>
                  </b-select>
                </div>
              </div>
            </div>
          </b-field>

          <div class="form-section-title">Ministries</div>
          <b-field class="ministries-field span-2">
            <div class="ministries-grid">
              <label v-for="ministry in ministries" :key="ministry" class="ministry-check">
                <input type="checkbox" :value="ministry" v-model="form.ministries" />
                <span>{{ ministry }}</span>
              </label>
            </div>
          </b-field>
        </form>
        <p v-if="error" class="att-save-error">{{ error }}</p>
        <div class="drawer-foot"><button class="button" type="button" @click="formOpen = false">Cancel</button><button class="button is-dark" type="button" :disabled="saving" @click="saveSchedule">{{ saving ? 'Saving...' : 'Save Service' }}</button></div>
      </div>
    </b-modal>

    <b-modal v-model="rangeOpen" :width="430">
      <div class="range-modal">
         <p class="eyebrow">Create Dated Services</p>
         <h2 class="drawer-title">Create Services for a Date Range</h2>
         <p class="range-help">Create one dated attendance service for each active schedule in this range.</p>
        <div class="range-fields">
          <b-datepicker v-model="rangeFrom" placeholder="From" format="MMM d, yyyy" icon="calendar-blank-outline" size="is-small" />
          <b-datepicker v-model="rangeTo" placeholder="To" format="MMM d, yyyy" icon="calendar-blank-outline" size="is-small" />
        </div>
        <div class="drawer-foot">
          <button class="button" type="button" @click="rangeOpen = false">Cancel</button>
          <button class="button is-dark" type="button" :disabled="rangeGenerating" @click="generateRange">
             {{ rangeGenerating ? 'Creating...' : 'Create Dated Services' }}
          </button>
        </div>
      </div>
    </b-modal>
  </app-page>
</template>

<script>
import { api, errorMessage } from '../services/api';
import { formatDate, formatTime12, parseTime24, composeTime24 } from '../utils/format';

export default {
  name: 'SchedulesPage',
  data() {
    return {
       schedules: [], selectedSchedule: null, loading: false, generating: false, rangeGenerating: false, rangeOpen: false, rangeFrom: null, rangeTo: null, saving: false, formOpen: false, editingSchedule: null, error: '', loadRequestSeq: 0,
       filters: { search: '', status: '', service_type: '', origin: '' },
      serviceTypeOptions: [
        { value: 'worship', label: 'Worship' },
        { value: 'prayer', label: 'Prayer' },
        { value: 'youth', label: 'Youth' },
      ],
      weekdays: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
      hourOptions: Array.from({ length: 12 }, (_, i) => String(i + 1)),
      minuteOptions: Array.from({ length: 12 }, (_, i) => String(i * 5).padStart(2, '0')),
      ministries: [
        'Admin Staff', "Children's Ministry", 'Compassion Ministry', 'Finance Ministry', 'Greeters Ministry',
        'Intercessory Ministry', 'Medical Ministry', "Men's Ministry", 'Multimedia Ministry', 'Pastoral Staff',
        'Praise and Worship Ministry', "Women's Ministry", 'Young Professional Circle (YPC) Ministry', 'Youth Alive Ministry',
      ],
      form: {
        start: parseTime24('08:00') || { hour: '8', minute: '00', period: 'AM' },
        end: parseTime24('12:00') || { hour: '12', minute: '00', period: 'PM' },
      },
    };
  },
  computed: {
    scheduleDayOptions() {
      return [...this.weekdays, 'Daily', 'Monthly', 'One-time'];
    },
    formServiceTypeOptions() {
      const options = this.serviceTypeOptions.slice();
      const current = this.form.service_type;
      if (current && !options.some(type => type.value === current)) {
        options.push({ value: current, label: current });
      }
      return options;
    },
    selectedScheduleActive() {
      return Boolean(this.selectedSchedule)
        && (this.selectedSchedule.is_active === true || Number(this.selectedSchedule.is_active) === 1);
    },
    editingBuiltIn() {
      return Boolean(this.editingSchedule) && Number(this.editingSchedule.is_default) === 1;
    },
  },
  mounted() { this.loadSchedules(); },
  beforeDestroy() { this.loadRequestSeq += 1; },
  methods: {
    async loadSchedules() {
      const requestId = ++this.loadRequestSeq;
      const params = {
        search: this.filters.search,
        status: this.filters.status,
        service_type: this.filters.service_type,
        origin: this.filters.origin,
      };
      this.loading = true;
      try {
        const payload = await api.get('/api/services', params);
        if (requestId !== this.loadRequestSeq) return;
        this.schedules = Array.isArray(payload.data) ? payload.data : [];
        if (this.selectedSchedule) this.selectedSchedule = this.schedules.find(row => Number(row.id) === Number(this.selectedSchedule.id)) || null;
      } catch (e) {
        if (requestId === this.loadRequestSeq) this.$buefy.toast.open({ message: errorMessage(e, 'Could not load services.'), type: 'is-danger' });
      }
      finally { if (requestId === this.loadRequestSeq) this.loading = false; }
    },
    selectSchedule(row) { this.selectedSchedule = row; },
    scheduleRowClass(row) {
      return this.selectedSchedule && Number(this.selectedSchedule.id) === Number(row.id) ? 'is-selected' : '';
    },
    scheduleDayLabel(row) {
      if (row.schedule_day === 'One-time') return 'One-time';
      if (row.schedule_day === 'Daily') return 'Daily';
      return row.schedule_day || 'Weekly';
    },
    recurrenceDetail(row) {
      if (row.schedule_day === 'One-time') return row.specific_date || '';
      if (row.schedule_day === 'Monthly' && row.day_of_month) return `Day ${row.day_of_month}`;
      return '';
    },
    timeLabel(row) {
      const start = formatTime12(row.start_time);
      const end = formatTime12(row.end_time);
      if (start && end) return `${start} - ${end}`;
      return start || '-';
    },
    ministryLabel(row) {
      const list = Array.isArray(row.ministries) ? row.ministries : [];
      if (!list.length) return '-';
      return list.length > 1 ? `${list[0]} +${list.length - 1}` : list[0];
    },
    openCreate() {
      this.editingSchedule = null; this.error = '';
      this.form = {
        name: '', description: '', schedule_day: 'Sunday', specific_date: '', day_of_month: null, service_type: 'worship',
        start: parseTime24('08:00') || { hour: '8', minute: '00', period: 'AM' },
        end: parseTime24('12:00') || { hour: '12', minute: '00', period: 'PM' },
        ministries: [],
      };
      this.formOpen = true;
    },
    openEdit(row) {
      this.editingSchedule = row; this.error = '';
      this.form = {
        name: row.name || row.schedule_name || '',
        description: row.description || row.session_title || '',
        schedule_day: row.schedule_day || 'Weekly',
        specific_date: row.specific_date || '',
        day_of_month: row.day_of_month || null,
        service_type: row.service_type || row.session_type || '',
        start: parseTime24(row.start_time) || { hour: '8', minute: '00', period: 'AM' },
        end: parseTime24(row.end_time) || { hour: '12', minute: '00', period: 'PM' },
        ministries: Array.isArray(row.ministries) ? [...row.ministries] : [],
      };
      this.formOpen = true;
    },
    async saveSchedule() {
      this.saving = true; this.error = '';
      try {
        const payload = {
          name: this.form.name,
          description: this.form.description,
          schedule_day: this.form.schedule_day,
          specific_date: this.form.schedule_day === 'One-time' ? (this.form.specific_date || null) : null,
          day_of_month: this.form.schedule_day === 'Monthly' ? this.form.day_of_month : null,
          start_time: this.form.start ? composeTime24(this.form.start.hour, this.form.start.minute, this.form.start.period) : null,
          end_time: this.form.end ? composeTime24(this.form.end.hour, this.form.end.minute, this.form.end.period) : null,
          ministries: this.form.ministries,
          service_type: this.form.service_type,
        };
        const url = this.editingSchedule ? `/api/services/${this.editingSchedule.id}` : '/api/services';
        const res = this.editingSchedule ? await api.patch(url, payload) : await api.post(url, payload);
        this.formOpen = false; this.$buefy.toast.open({ message: res.message || 'Service saved.', type: 'is-success' }); await this.loadSchedules();
      } catch (e) { this.error = errorMessage(e, 'Could not save service.'); }
      finally { this.saving = false; }
    },
    async toggleSchedule(row) {
      try { const payload = await api.patch(`/api/services/${row.id}/toggle`); this.$buefy.toast.open({ message: payload.message || 'Service updated.', type: 'is-success' }); await this.loadSchedules(); }
      catch (e) { this.$buefy.toast.open({ message: errorMessage(e, 'Could not update service.'), type: 'is-danger' }); }
    },
    confirmDelete(row) {
      this.$buefy.dialog.confirm({ title: 'Delete Service', message: `Delete "${row.name}"?`, type: 'is-danger', confirmText: 'Delete', onConfirm: () => this.deleteSchedule(row) });
    },
    async deleteSchedule(row) {
      try { const payload = await api.delete(`/api/services/${row.id}`); this.$buefy.toast.open({ message: payload.message || 'Service deleted.', type: 'is-success' }); await this.loadSchedules(); }
      catch (e) { this.$buefy.toast.open({ message: errorMessage(e, 'Could not delete service.'), type: 'is-danger' }); }
    },
    async generateToday() {
      if (!this.selectedScheduleActive || this.generating) return;
      const serviceId = this.selectedSchedule.id;
      this.generating = true;
      try {
        const payload = await api.post('/api/services/generate', { date: formatDate(new Date()), service_id: serviceId });
        const generated = Number(payload.data && payload.data.generated) || 0;
        if (!generated) {
          this.$buefy.toast.open({ message: 'No session was generated for this service today.', type: 'is-warning' });
          return;
        }
        this.$buefy.toast.open({ message: payload.message || 'Session generated.', type: 'is-success' });
        this.$router.push('/attendance');
      }
      catch (e) { this.$buefy.toast.open({ message: errorMessage(e, 'Could not generate session.'), type: 'is-danger' }); }
      finally { this.generating = false; }
    },
    async generateRange() {
      if (!this.rangeFrom || !this.rangeTo) {
        this.$buefy.toast.open({ message: 'Choose both dates.', type: 'is-warning' });
        return;
      }
      const from = formatDate(this.rangeFrom);
      const to = formatDate(this.rangeTo);
      if (from > to) {
        this.$buefy.toast.open({ message: 'The end date must be after the start date.', type: 'is-warning' });
        return;
      }
      this.rangeGenerating = true;
      try {
        const payload = await api.post('/api/services/generate', { from, to });
        const generated = Number(payload.data && payload.data.generated) || 0;
        if (!generated) {
          this.$buefy.toast.open({ message: 'No sessions were generated in this range.', type: 'is-warning' });
          return;
        }
        this.$buefy.toast.open({ message: payload.message || 'Sessions generated.', type: 'is-success' });
        this.rangeOpen = false;
        this.$router.push('/attendance');
      } catch (e) {
        this.$buefy.toast.open({ message: errorMessage(e, 'Could not generate sessions.'), type: 'is-danger' });
      } finally {
        this.rangeGenerating = false;
      }
    },
  },
};
</script>

<style scoped>
.work-area > .table-panel,
.work-area > .side-panel {
  min-height: 0;
}

.schedule-table-wrap {
  width: 100%;
}

.schedule-table-wrap .cell-title,
.schedule-table-wrap .cell-sub {
  display: block;
}

.schedule-table ::v-deep .table {
  min-width: 760px;
}

.schedule-table ::v-deep tbody tr {
  cursor: pointer;
}

.schedule-table ::v-deep tbody tr.is-selected td {
  color: inherit;
  background: #eef1f4;
}

.schedule-description {
  max-width: 420px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.schedule-category {
  text-transform: capitalize;
}

.schedule-time {
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.schedule-help {
  margin: 14px 0 0;
  color: #6b7280;
  font-size: 12px;
  line-height: 1.5;
}

.range-modal,
.schedule-modal {
  padding: 20px;
  border: 1px solid #e5e7eb;
  border-radius: 0;
  background: #fff;
  box-shadow: none;
}

.range-help { margin: 8px 0 16px; color: #6b7280; font-size: 12px; }
.range-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.schedule-modal .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.schedule-modal .field { margin-bottom: 0; }
.span-2 { grid-column: 1 / -1; }
.form-section-title { grid-column: 1 / -1; margin: 8px 0 0; padding-bottom: 6px; border-bottom: 1px solid #eef0f2; color: #8490a5; font-size: 10px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
.time-range { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; width: 100%; }
.time-group { display: flex; flex-direction: column; gap: 5px; }
.time-group-label { font-size: 10px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #6b7280; }
.time-picker { display: flex; width: 100%; border: 0; border-radius: 0; overflow: hidden; background: #fff; transition: border-color .15s ease, box-shadow .15s ease; }
.time-picker:focus-within { box-shadow: 0 0 0 3px rgba(41, 50, 58, .08); }
.time-picker .select { flex: 1; min-width: 0; }
.time-picker .select select { width: 100%; height: 34px; border: 0; border-radius: 0; box-shadow: none; background: transparent; cursor: pointer; }
.time-picker .select:last-child select { border-right: 0; }
.time-colon { display: flex; align-items: center; padding: 0 7px; color: #6b7280; font-weight: 700; background: transparent; user-select: none; }
.ministries-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 6px 12px; padding: 8px 10px; border: 1px solid #dbdbdb; border-radius: 0; max-height: 180px; overflow-y: auto; }
.ministry-check { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #374151; }
.time-picker:focus-within { box-shadow: none; }
.time-picker .select select { border-right: none; height: 34px; }
.time-colon { border-right: none; background: transparent; }

@media (max-width: 900px) {
  .schedule-modal .form-grid { grid-template-columns: 1fr; }
}

@media (max-width: 640px) {
  .range-fields { grid-template-columns: 1fr; }
  .schedule-modal, .range-modal { padding: 14px; }
}
@media (max-width: 560px) { .time-range { grid-template-columns: 1fr; } }

@media (max-width: 768px) {
  .schedule-modal,
  .range-modal {
    padding: 12px;
  }

  .form-section-title {
    margin-top: 4px;
    padding-bottom: 4px;
    font-size: 9px;
  }
}
</style>
