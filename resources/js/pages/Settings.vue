<template>
  <section class="settings-page">
    <div class="settings-shell">
      <header class="settings-hero">
        <div>
          <span class="settings-eyebrow">Control Center</span>
          <h1>System Settings</h1>
          <p>Configure the admin menu, review integration health, and open page guides for the attendance system.</p>
        </div>
        <div class="settings-hero-actions">
          <b-button type="is-light" size="is-small" :loading="syncLoading" @click="loadSyncStatus">Refresh Status</b-button>
          <b-button type="is-dark" size="is-small" :loading="fetching" @click="fetchMembers">Fetch Members</b-button>
        </div>
      </header>

      <div class="settings-layout">
        <aside class="settings-nav">
          <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            class="settings-nav-item"
            :class="{ active: activeTab === tab.key }"
            @click="activeTab = tab.key"
          >
            <b-icon :icon="tab.icon" size="is-small"></b-icon>
            <span>{{ tab.label }}</span>
          </button>
        </aside>

        <main class="settings-content">
          <section v-if="activeTab === 'overview'" class="settings-section">
            <div class="section-head">
              <div>
                <span class="settings-eyebrow">Overview</span>
                <h2>System Snapshot</h2>
              </div>
            </div>

            <div class="settings-grid three">
              <div class="setting-card">
                <span class="setting-label">App Mode</span>
                <strong>Attendance Admin</strong>
                <p>Session-based attendance with public kiosk scanning.</p>
              </div>
              <div class="setting-card">
                <span class="setting-label">Kiosk Birthday Rule</span>
                <strong>Current Month</strong>
                <p>Celebrates members whose birthdays fall anywhere in the current month.</p>
              </div>
              <div class="setting-card">
                <span class="setting-label">Dashboard Refresh</span>
                <strong>Every 30 seconds</strong>
                <p>Summary and insights auto-refresh while the dashboard is open.</p>
              </div>
              <div class="setting-card">
                <span class="setting-label">Attendance Summary</span>
                <strong>Top Strip</strong>
                <p>Totals for the filtered list: sessions, present, records, and attendance rate.</p>
              </div>
              <div class="setting-card">
                <span class="setting-label">Attendance Refresh</span>
                <strong>Every 30 seconds</strong>
                <p>Session list and the open roster reload automatically while viewing.</p>
              </div>
              <div class="setting-card">
                <span class="setting-label">Roster Tools</span>
                <strong>Search · Sort · Export</strong>
                <p>Filter by name/code, sort by check-in time, export to CSV, or jump to a member profile.</p>
              </div>
            </div>

            <div class="settings-grid two">
              <div class="setting-card">
                <div class="card-title-row">
                  <h3>Current Modules</h3>
                  <span class="status-pill good">Enabled</span>
                </div>
                <ul class="settings-list">
                  <li>Dashboard grouped summary boxes, reminders, and attendance insights</li>
                  <li>Attendance summary strip with live 30s refresh</li>
                  <li>Roster search, sort, CSV export, and member profile jump</li>
                  <li>Session quick actions: Start, Complete, Cancel, Reopen</li>
                  <li>Kiosk duplicate warning, sound pack, photos, and offline queue</li>
                  <li>Monthly birthday celebrants list and birthday celebration screen</li>
                </ul>
              </div>
              <div class="setting-card">
                <div class="card-title-row">
                  <h3>Safe Settings First</h3>
                  <span class="status-pill neutral">Read-only</span>
                </div>
                <p>This first version keeps risky operations non-destructive. Editable backend settings can be added next after the guide structure is stable.</p>
                <ul class="settings-list">
                  <li>No database reset controls</li>
                  <li>No kiosk key regeneration yet</li>
                  <li>No destructive record cleanup yet</li>
                </ul>
              </div>
            </div>
          </section>

          <section v-if="activeTab === 'menu'" class="settings-section">
            <div class="section-head">
              <div>
                <span class="settings-eyebrow">Navigation</span>
                <h2>Sidebar Menu</h2>
                <p>Stored in <code>storage/app/sidebar-menu.json</code>. You can also edit that file directly.</p>
              </div>
              <div class="section-actions">
                <b-button type="is-light" size="is-small" :loading="loading" @click="resetDefaults">Reset to Defaults</b-button>
                <b-button type="is-light" size="is-small" @click="addItem">+ Add Item</b-button>
                <b-button type="is-dark" size="is-small" :loading="saving" @click="save">Save Menu</b-button>
              </div>
            </div>

            <div v-if="loading" class="empty-state">
              <p class="empty-title">Loading menu...</p>
            </div>

            <div v-else-if="menu.length" class="menu-editor">
              <div class="menu-row" v-for="(item, i) in menu" :key="i">
                <div class="row-reorder">
                  <button class="reorder-btn" :disabled="i === 0" @click="moveUp(i)" title="Move up">▲</button>
                  <button class="reorder-btn" :disabled="i === menu.length - 1" @click="moveDown(i)" title="Move down">▼</button>
                </div>
                <div class="row-icon"><b-icon :icon="item.icon || 'menu'" size="is-small"></b-icon></div>
                <div class="row-field">
                  <label class="row-label">Label</label>
                  <b-input v-model="item.label" size="is-small" placeholder="e.g. Members"></b-input>
                </div>
                <div class="row-field row-path">
                  <label class="row-label">Page / Route</label>
                  <b-autocomplete v-model="item.path" :data="availablePaths" size="is-small" placeholder="e.g. /members" open-on-focus></b-autocomplete>
                </div>
                <div class="row-field row-icon-field">
                  <label class="row-label">Icon (MDI)</label>
                  <b-input v-model="item.icon" size="is-small" placeholder="e.g. account-group-outline"></b-input>
                </div>
                <div class="row-field row-toggle">
                  <label class="row-label">Exact</label>
                  <b-checkbox v-model="item.exact" size="is-small"></b-checkbox>
                </div>
                <div class="row-field row-toggle">
                  <label class="row-label">Visible</label>
                  <b-checkbox v-model="item.enabled" size="is-small"></b-checkbox>
                </div>
                <div class="row-del"><button class="delete-btn" @click="removeItem(i)">Remove</button></div>
              </div>
            </div>

            <div v-else class="empty-state">
              <p class="empty-title">No menu items</p>
              <p class="empty-desc">Add at least one item so you can navigate the app.</p>
            </div>
          </section>

          <section v-if="activeTab === 'kiosk'" class="settings-section">
            <div class="section-head">
              <div>
                <span class="settings-eyebrow">Public Scanner</span>
                <h2>Kiosk Settings</h2>
                <p>Configure announcement video, scan behavior, and kiosk options.</p>
              </div>
            </div>

            <div class="setting-card">
              <h3>Announcement Video</h3>
              <p>YouTube or video URL displayed on the kiosk screen. Leave empty to hide.</p>
              <div class="announcement-input-row">
                <b-input
                  v-model="announcementUrl"
                  size="is-small"
                  placeholder="https://www.youtube.com/watch?v=..."
                  :loading="announcementLoading"
                />
              </div>
            </div>

            <div class="setting-card">
              <h3>Kiosk Sound</h3>
              <p>Play a sound when a member scans in. Disable to mute the kiosk.</p>
              <div class="announcement-input-row">
                <b-switch v-model="kioskSoundEnabled" :loading="announcementLoading" @input="saveKioskSettings">
                  {{ kioskSoundEnabled ? 'Enabled' : 'Disabled' }}
                </b-switch>
              </div>
            </div>

            <div class="setting-card">
              <h3>Device Provisioning</h3>
              <p>Authorize this browser for kiosk check-ins. The credential stays in this browser and is not embedded in public code.</p>
              <b-button type="is-dark" size="is-small" :loading="kioskProvisioning" @click="provisionKiosk">
                {{ kioskProvisioned ? 'Browser Provisioned' : 'Provision This Browser' }}
              </b-button>
            </div>

            <div class="section-actions">
              <b-button type="is-dark" size="is-small" :loading="announcementSaving" @click="saveKioskSettings">Save Kiosk Settings</b-button>
              <span v-if="announcementSaved" class="announcement-saved">Saved</span>
            </div>

            <div class="settings-grid two">
              <div class="setting-card">
                <h3>Current Kiosk Behavior</h3>
                <ul class="settings-list">
                  <li>Valid scans check in through <code>/api/v1/attendance/check-in</code>.</li>
                  <li>Duplicate scans show the previous check-in time.</li>
                  <li>Birthday celebration appears for birthdays in the current month.</li>
                  <li>"Total Unique Attendance" counter updates every 10 seconds.</li>
                  <li>Sounds can be muted from the kiosk header.</li>
                  <li>Offline scans queue locally and sync when internet returns.</li>
                </ul>
              </div>
              <div class="setting-card">
                <h3>Recommended Editable Settings Next</h3>
                <ul class="settings-list">
                  <li>Enable or disable kiosk</li>
                  <li>Birthday mode: exact day or current month</li>
                  <li>Enable sounds by default</li>
                  <li>Enable member photos</li>
                  <li>Recent scan limit and welcome text</li>
                </ul>
              </div>
            </div>
          </section>

          <section v-if="activeTab === 'integration'" class="settings-section">
            <div class="section-head">
              <div>
                <span class="settings-eyebrow">Members</span>
                <h2>External Integration</h2>
                <p>Reads members from an external JSON endpoint. Attendance records reference external members by <code>member_code</code>.</p>
              </div>
              <div class="section-actions">
                <b-button type="is-light" size="is-small" :loading="syncLoading" @click="loadSyncStatus">Refresh Status</b-button>
                <b-button type="is-dark" size="is-small" :loading="fetching" @click="fetchMembers">Fetch Members</b-button>
              </div>
            </div>

            <div v-if="syncStatus === null" class="empty-state">
              <p class="empty-title">Not configured</p>
              <p class="empty-desc">Set INTEGRATION_URL and INTEGRATION_KEY in your .env file, then refresh status.</p>
            </div>

            <div v-else class="sync-grid">
              <div class="sync-stat">
                <span class="row-label">Endpoint</span>
                <span class="sync-value">{{ syncStatus.url || '-' }}</span>
              </div>
              <div class="sync-stat">
                <span class="row-label">Mapped fields</span>
                <span class="sync-value">{{ syncStatus.fields_mapped }}</span>
              </div>
              <div class="sync-stat">
                <span class="row-label">Sample payload</span>
                <span class="sync-value">{{ syncStatus.sample_available ? 'Available' : 'None' }}</span>
              </div>
              <div class="sync-stat">
                <span class="row-label">Last fetched</span>
                <span class="sync-value">{{ lastFetchText }}</span>
              </div>
            </div>
          </section>

          <section v-if="activeTab === 'system'" class="settings-section">
            <div class="section-head">
              <div>
                <span class="settings-eyebrow">Health</span>
                <h2>System Info</h2>
                <p>Live runtime, database, storage, and feature configuration checks for troubleshooting.</p>
              </div>
              <div class="section-actions">
                <b-button type="is-light" size="is-small" :loading="systemInfoLoading" @click="loadSystemInfo">Refresh Info</b-button>
              </div>
            </div>

            <div v-if="systemInfoLoading && !systemInfo" class="empty-state">
              <p class="empty-title">Loading system info...</p>
            </div>

            <div v-else-if="systemInfo" class="settings-grid two">
              <div class="setting-card">
                <div class="card-title-row">
                  <h3>Application</h3>
                  <span class="status-pill" :class="systemInfo.app.debug ? 'warning' : 'good'">
                    {{ systemInfo.app.debug ? 'Debug On' : 'Stable' }}
                  </span>
                </div>
                <dl class="info-list">
                  <div><dt>Name</dt><dd>{{ systemInfo.app.name || '-' }}</dd></div>
                  <div><dt>Environment</dt><dd>{{ systemInfo.app.environment || '-' }}</dd></div>
                  <div><dt>Timezone</dt><dd>{{ systemInfo.app.timezone || '-' }}</dd></div>
                  <div><dt>Server Time</dt><dd>{{ systemInfo.app.server_time || '-' }}</dd></div>
                </dl>
              </div>

              <div class="setting-card">
                <h3>Runtime</h3>
                <dl class="info-list">
                  <div><dt>PHP</dt><dd>{{ systemInfo.runtime.php_version || '-' }}</dd></div>
                  <div><dt>Laravel</dt><dd>{{ systemInfo.runtime.laravel_version || '-' }}</dd></div>
                  <div><dt>Cache Driver</dt><dd>{{ systemInfo.features.cache_driver || '-' }}</dd></div>
                  <div><dt>Queue</dt><dd>{{ systemInfo.features.queue_connection || '-' }}</dd></div>
                </dl>
              </div>

              <div class="setting-card">
                <div class="card-title-row">
                  <h3>Database</h3>
                  <span class="status-pill" :class="systemInfo.database.status === 'ok' ? 'good' : 'bad'">
                    {{ systemInfo.database.status }}
                  </span>
                </div>
                <dl class="info-list">
                  <div><dt>Connection</dt><dd>{{ systemInfo.database.connection || '-' }}</dd></div>
                  <div><dt>Database</dt><dd>{{ systemInfo.database.database || '-' }}</dd></div>
                  <div><dt>Message</dt><dd>{{ systemInfo.database.message || '-' }}</dd></div>
                </dl>
              </div>

              <div class="setting-card">
                <h3>Feature Checks</h3>
                <dl class="info-list">
                  <div><dt>Attendance Mode</dt><dd>{{ systemInfo.features.attendance_mode || '-' }}</dd></div>
                  <div><dt>Kiosk Key</dt><dd>{{ yesNo(systemInfo.features.kiosk_key_configured) }}</dd></div>
                  <div><dt>Integration</dt><dd>{{ yesNo(systemInfo.features.integration_configured) }}</dd></div>
                  <div><dt>Storage Writable</dt><dd>{{ yesNo(systemInfo.storage.storage_path_writable) }}</dd></div>
                  <div><dt>Logs Writable</dt><dd>{{ yesNo(systemInfo.storage.logs_path_writable) }}</dd></div>
                </dl>
              </div>
            </div>

            <div v-else class="empty-state">
              <p class="empty-title">System info unavailable</p>
              <p class="empty-desc">Refresh the page or check API access.</p>
            </div>
          </section>

          <section v-if="activeTab === 'guides'" class="settings-section">
            <div class="section-head">
              <div>
                <span class="settings-eyebrow">Help</span>
                <h2>Page Guides</h2>
                <p>Quick help for every main page so admins know what each page does.</p>
              </div>
            </div>

            <div class="guide-list">
              <article v-for="guide in pageGuides" :key="guide.title" class="guide-card">
                <div v-if="guide.image" class="guide-image">
                  <img :src="guide.image" :alt="guide.title + ' screenshot'" />
                </div>
                <div class="guide-body">
                  <div class="guide-title-row">
                    <b-icon :icon="guide.icon" size="is-small"></b-icon>
                    <h3>{{ guide.title }}</h3>
                  </div>
                  <p>{{ guide.description }}</p>
                  <ul class="settings-list">
                    <li v-for="item in guide.points" :key="item">{{ item }}</li>
                  </ul>
                </div>
              </article>
            </div>
          </section>

          <section v-if="activeTab === 'maintenance'" class="settings-section">
            <div class="section-head">
              <div>
                <span class="settings-eyebrow">Operations</span>
                <h2>Maintenance Guide</h2>
                <p>Safe operating checklist. Destructive tools are intentionally not added yet.</p>
              </div>
            </div>

            <div class="settings-grid two">
              <div class="setting-card">
                <h3>Safe Tools Available Now</h3>
                <ul class="settings-list">
                  <li>Refresh integration status</li>
                  <li>Fetch external members cache</li>
                  <li>Edit admin sidebar menu</li>
                  <li>Reset sidebar menu to defaults</li>
                </ul>
              </div>
              <div class="setting-card danger-soft">
                <h3>Future Admin Tools</h3>
                <p>These should require confirmation, audit logs, and admin-only permissions before implementation.</p>
                <ul class="settings-list">
                  <li>Regenerate kiosk API key</li>
                  <li>Clear app cache</li>
                  <li>Generate upcoming sessions</li>
                  <li>Export backup data</li>
                  <li>Download recent logs</li>
                </ul>
              </div>
            </div>
          </section>

          <section v-if="activeTab === 'feedback'" class="settings-section">
            <div class="section-head">
              <div>
                <span class="settings-eyebrow">Improve</span>
                <h2>System Feedback</h2>
                <p>Report issues or suggest improvements. The developer will review your feedback.</p>
              </div>
              <div class="section-actions">
                <b-button type="is-dark" size="is-small" icon-left="plus" @click="openFeedbackForm">New Feedback</b-button>
              </div>
            </div>

            <div v-if="feedbackItems.length" class="feedback-list">
              <article v-for="item in feedbackItems" :key="item.id" class="feedback-card">
                <div class="feedback-card-head">
                  <div class="feedback-card-left">
                    <span class="category-tag" :class="fbCatClass(item.category)">{{ item.category }}</span>
                    <span v-if="item.rating" class="rating-tag" :class="fbRatingClass(item.rating)">{{ item.rating }}</span>
                  </div>
                  <time>{{ formatFbDate(item.created_at) }}</time>
                </div>
                <p class="feedback-message">{{ item.message }}</p>
              </article>
            </div>

            <div v-else class="empty-state">
              <p class="empty-title">No feedback yet</p>
              <p class="empty-desc">Click "New Feedback" to report an issue or suggest an improvement.</p>
            </div>

            <b-modal :active.sync="feedbackFormOpen" has-modal-card :width="520" @close="resetFeedbackForm">
              <div class="feedback-form-modal">
                <h3 class="feedback-form-title">Submit Feedback</h3>
                <div class="feedback-form-body">
                  <b-field label="Category">
                    <b-select v-model="feedbackForm.category" expanded>
                      <option v-for="cat in feedbackCategories" :key="cat" :value="cat">{{ cat }}</option>
                    </b-select>
                  </b-field>
                  <b-field label="Rating">
                    <b-select v-model="feedbackForm.rating" expanded>
                      <option value="">No rating</option>
                      <option value="Smooth">Smooth</option>
                      <option value="Okay">Okay</option>
                      <option value="Needs Improvement">Needs Improvement</option>
                    </b-select>
                  </b-field>
                  <b-field label="Message">
                    <b-input v-model="feedbackForm.message" type="textarea" rows="4" placeholder="Report an issue or suggest an improvement..." />
                  </b-field>
                </div>
                <div class="feedback-form-foot">
                  <b-button type="is-light" @click="feedbackFormOpen = false">Cancel</b-button>
                  <b-button type="is-dark" :loading="feedbackSaving" @click="submitFeedback">Submit</b-button>
                </div>
              </div>
            </b-modal>
          </section>
        </main>
      </div>
    </div>
  </section>
</template>

<script>
import { api, errorMessage } from '../services/api';

export default {
  name: 'Settings',

  data() {
    return {
      activeTab: 'overview',
      tabs: [
        { key: 'overview', label: 'Overview', icon: 'view-dashboard-outline' },
        { key: 'menu', label: 'Menu', icon: 'menu' },
        { key: 'kiosk', label: 'Kiosk', icon: 'qrcode-scan' },
        { key: 'integration', label: 'Integration', icon: 'cloud-sync-outline' },
        { key: 'system', label: 'System Info', icon: 'monitor-dashboard' },
        { key: 'guides', label: 'Page Guides', icon: 'help-circle-outline' },
        { key: 'maintenance', label: 'Maintenance', icon: 'tools' },
        { key: 'feedback', label: 'Feedback', icon: 'message-alert-outline' },
      ],
      menu: [],
      availablePaths: [],
      loading: false,
      saving: false,
      syncStatus: null,
      syncLoading: false,
      fetching: false,
      systemInfo: null,
      systemInfoLoading: false,
      feedbackItems: [],
      feedbackCategories: [],
      feedbackFormOpen: false,
      feedbackSaving: false,
      feedbackForm: { category: 'General', rating: '', message: '' },
      announcementUrl: '',
      kioskSoundEnabled: true,
      announcementLoading: false,
      announcementSaving: false,
      announcementSaved: false,
      kioskProvisioning: false,
      kioskProvisioned: Boolean(localStorage.getItem('cas_kiosk_api_key')),
      pageGuides: [
        {
          title: 'Dashboard',
          icon: 'view-dashboard-outline',
          image: '/images/dashboard.png',
          description: 'Shows live totals, latest sessions, reminders, and attendance insights.',
          points: ['Grouped summary boxes cover members and sessions.', 'Summary and insights auto-refresh every 30 seconds.', 'Recent sessions open the attendance page.', 'Insights highlight new attendees, ministry attendance, and missed-session streaks.'],
        },
        {
          title: 'Attendance',
          icon: 'calendar-check-outline',
          image: '/images/attendance.png',
          description: 'Main admin area for managing session records and session status.',
          points: ['Summary strip shows totals for the filtered session list.', 'Sessions and the open roster refresh every 30 seconds.', 'Start, complete, cancel, or reopen sessions.', 'Search or sort the roster, export it to CSV, or open a member profile.'],
        },
        {
          title: 'Members',
          icon: 'account-group-outline',
          image: '/images/members.png',
          description: 'Displays external members without importing them into the local member table.',
          points: ['Search by name or code.', 'Member data comes from the configured external API.', 'Attendance records store member_code references.'],
        },
        {
          title: 'Schedules',
          icon: 'calendar-clock-outline',
          image: '/images/schedules.png',
          description: 'Controls recurring Sunday service/session schedules.',
          points: ['Generate upcoming sessions.', 'Edit service time and type.', 'Disable schedules when there is no service.'],
        },
        {
          title: 'History',
          icon: 'history',
          image: '/images/history.png',
          description: 'Review past attendance records and historical data.',
          points: ['Browse previous attendance sessions.', 'Search and filter old records.', 'Verify past attendance activity.'],
        },
        {
          title: 'Reports',
          icon: 'file-chart-outline',
          image: '/images/report.png',
          description: 'Filters attendance data and exports report summaries.',
          points: ['Filter by date and session.', 'View totals and breakdowns.', 'Export PDF reports when needed.'],
        },
        {
          title: 'Kiosk',
          icon: 'qrcode-scan',
          image: '/images/scannerPage.png',
          description: 'Public QR scanner used during live sessions.',
          points: ['Scans check members into the active session.', 'A live total of unique attendees is always visible.', 'Duplicate scans show previous check-in time.', 'Offline scans sync when internet returns.'],
        },
        {
          title: 'Settings',
          icon: 'cog-outline',
          image: '/images/setttings.png',
          description: 'Admin control center for menu, guides, integration, kiosk notes, and maintenance planning.',
          points: ['Use Menu to manage sidebar navigation.', 'Use Integration to check external member status.', 'Use Guides to train admins on every page.', 'Use Feedback to report issues or suggest improvements.'],
        },
      ],
    };
  },

  computed: {
    lastFetchText() {
      const value = this.syncStatus && this.syncStatus.last_fetched_at;
      return value || 'Never';
    },
  },

  async created() {
    this.loading = true;
    try {
      const [menuPayload, routesPayload] = await Promise.all([
        api.get('/api/sidebar'),
        api.get('/api/sidebar/routes'),
      ]);
      this.menu = (menuPayload.data || []).map((item) => ({ ...item }));
      this.availablePaths = (routesPayload.data || []).map((route) => route.path);
    } catch (e) {
      this.$buefy.toast.open({ message: errorMessage(e, 'Could not load sidebar settings.'), type: 'is-danger' });
    } finally {
      this.loading = false;
    }
    this.loadSyncStatus();
    this.loadSystemInfo();
    this.loadFeedbackMeta();
    this.loadFeedback();
    this.loadAnnouncement();
  },

  methods: {
    async loadSyncStatus() {
      this.syncLoading = true;
      try {
        const payload = await api.get('/api/integration/status');
        this.syncStatus = payload.data || null;
      } catch (e) {
        this.syncStatus = null;
        this.$buefy.toast.open({ message: errorMessage(e, 'Could not load integration status.'), type: 'is-danger' });
      } finally {
        this.syncLoading = false;
      }
    },

    async loadSystemInfo() {
      this.systemInfoLoading = true;
      try {
        const payload = await api.get('/api/system/info');
        this.systemInfo = payload.data || null;
      } catch (e) {
        this.systemInfo = null;
        this.$buefy.toast.open({ message: errorMessage(e, 'Could not load system info.'), type: 'is-danger' });
      } finally {
        this.systemInfoLoading = false;
      }
    },

    yesNo(value) {
      return value ? 'Yes' : 'No';
    },

    async fetchMembers() {
      this.fetching = true;
      try {
        const payload = await api.get('/api/integration/fetch', { refresh: 1 });
        this.$buefy.toast.open({ message: payload.message || 'External members fetched.', type: 'is-success' });
        await this.loadSyncStatus();
      } catch (e) {
        this.$buefy.toast.open({ message: errorMessage(e, 'Fetch failed.'), type: 'is-danger' });
      } finally {
        this.fetching = false;
      }
    },

    addItem() {
      this.menu.push({ path: '', label: '', icon: 'menu', exact: false, enabled: true });
    },

    removeItem(index) {
      this.menu.splice(index, 1);
    },

    moveUp(index) {
      if (index === 0) return;
      const arr = this.menu;
      [arr[index - 1], arr[index]] = [arr[index], arr[index - 1]];
    },

    moveDown(index) {
      if (index === this.menu.length - 1) return;
      const arr = this.menu;
      [arr[index + 1], arr[index]] = [arr[index], arr[index + 1]];
    },

    async save() {
      const items = this.menu.filter((item) => item.path && item.label.trim());
      if (!items.length) {
        this.$buefy.toast.open({ message: 'Add at least one item with a label and route.', type: 'is-danger' });
        return;
      }

      this.saving = true;
      try {
        const payload = await api.post('/api/sidebar', { items });
        this.menu = (payload.data || items).map((item) => ({ ...item }));
        this.$root.$emit('sidebar-updated');
        this.$buefy.toast.open({ message: payload.message || 'Sidebar updated.', type: 'is-success' });
      } catch (e) {
        this.$buefy.toast.open({ message: errorMessage(e, 'Could not save sidebar.'), type: 'is-danger' });
      } finally {
        this.saving = false;
      }
    },

    async resetDefaults() {
      this.loading = true;
      try {
        const payload = await api.delete('/api/sidebar');
        this.menu = (payload.data || []).map((item) => ({ ...item }));
        this.$root.$emit('sidebar-updated');
        this.$buefy.toast.open({ message: payload.message || 'Sidebar reset to defaults.', type: 'is-success' });
      } catch (e) {
        this.$buefy.toast.open({ message: errorMessage(e, 'Could not reset sidebar.'), type: 'is-danger' });
      } finally {
        this.loading = false;
      }
    },

    formatFbDate: function (value) {
      if (!value) return '';
      var date = new Date(value.replace(' ', 'T'));
      if (Number.isNaN(date.getTime())) return value;
      return date.toLocaleString([], { month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    },
    fbCatClass: function (cat) {
      var map = { Login: 'cat-login', Dashboard: 'cat-dashboard', Members: 'cat-members', Schedules: 'cat-schedules', Attendance: 'cat-attendance', Kiosk: 'cat-kiosk' };
      return map[cat] || 'cat-default';
    },
    fbRatingClass: function (rating) {
      var map = { Smooth: 'rt-smooth', Okay: 'rt-okay', 'Needs Improvement': 'rt-needs' };
      return map[rating] || '';
    },
    openFeedbackForm: function () {
      this.feedbackForm = { category: 'General', rating: '', message: '' };
      this.feedbackFormOpen = true;
    },
    resetFeedbackForm: function () {
      this.feedbackForm = { category: 'General', rating: '', message: '' };
    },
    loadFeedbackMeta: function () {
      var self = this;
      api.get('/api/system-feedback/categories').then(function (res) {
        self.feedbackCategories = res.data || [];
      }).catch(function () {});
    },
    loadFeedback: function () {
      var self = this;
      api.get('/api/system-feedback').then(function (payload) {
        self.feedbackItems = payload.data || [];
      }).catch(function () {});
    },
    submitFeedback: function () {
      var self = this;
      if (!self.feedbackForm.message.trim()) {
        self.$buefy.toast.open({ message: 'Please enter a message.', type: 'is-warning' });
        return;
      }
      self.feedbackSaving = true;
      api.post('/api/system-feedback', {
        category: self.feedbackForm.category,
        rating: self.feedbackForm.rating || null,
        message: self.feedbackForm.message,
      }).then(function () {
        self.feedbackFormOpen = false;
        self.resetFeedbackForm();
        self.loadFeedback();
        self.$buefy.toast.open({ message: 'Feedback submitted.', type: 'is-success' });
      }).catch(function (e) {
        self.$buefy.toast.open({ message: errorMessage(e, 'Could not submit feedback.'), type: 'is-danger' });
      }).finally(function () {
        self.feedbackSaving = false;
      });
    },

    async loadAnnouncement() {
      this.announcementLoading = true;
      try {
        const payload = await api.get('/api/settings/announcement');
        const data = payload.data || {};
        this.announcementUrl = data.video_url || '';
        this.kioskSoundEnabled = data.sound_enabled !== false;
      } catch (e) {
        // silent
      } finally {
        this.announcementLoading = false;
      }
    },

    async saveKioskSettings() {
      this.announcementSaving = true;
      this.announcementSaved = false;
      try {
        await api.post('/api/settings/announcement', {
          video_url: this.announcementUrl,
          sound_enabled: this.kioskSoundEnabled,
        });
        this.announcementSaved = true;
        setTimeout(() => { this.announcementSaved = false; }, 2000);
      } catch (e) {
        this.$buefy.toast.open({ message: errorMessage(e, 'Could not save.'), type: 'is-danger' });
      } finally {
        this.announcementSaving = false;
      }
    },

    async provisionKiosk() {
      this.kioskProvisioning = true;
      try {
        const payload = await api.post('/api/settings/kiosk/provision');
        const key = payload.data && payload.data.kiosk_key;
        if (!key) throw new Error('The server did not return a kiosk credential.');
        localStorage.setItem('cas_kiosk_api_key', key);
        this.kioskProvisioned = true;
        this.$buefy.toast.open({ message: payload.message || 'This browser is ready for kiosk check-ins.', type: 'is-success' });
      } catch (e) {
        this.$buefy.toast.open({ message: errorMessage(e, 'Could not provision this browser.'), type: 'is-danger' });
      } finally {
        this.kioskProvisioning = false;
      }
    },
  },
};
</script>

<style scoped>
.settings-page {
  height: calc(100vh - 56px);
  padding: 12px 14px;
  color: #20262e;
  font-family: Inter, "Segoe UI", Arial, sans-serif;
  background: #f5f6f7;
  overflow: hidden;
}

.settings-shell {
  height: 100%;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.settings-hero {
  flex: 0 0 auto;
  display: flex;
  justify-content: space-between;
  gap: 16px;
  padding: 16px 18px;
  background: linear-gradient(135deg, #17223b, #31598e);
  color: #fff;
}

.settings-hero h1 {
  margin: 2px 0 4px;
  font-size: 24px;
  line-height: 1.1;
}

.settings-hero p {
  max-width: 760px;
  margin: 0;
  color: rgba(255, 255, 255, 0.76);
  font-size: 12px;
}

.settings-hero-actions,
.section-actions {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  flex-wrap: wrap;
}

.settings-eyebrow {
  display: inline-block;
  color: #7b8daa;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.settings-hero .settings-eyebrow {
  color: rgba(255, 255, 255, 0.68);
}

.settings-layout {
  flex: 1;
  min-height: 0;
  display: grid;
  grid-template-columns: 190px minmax(0, 1fr);
  gap: 12px;
}

.settings-nav {
  min-height: 0;
  overflow-y: auto;
  padding: 8px;
  background: #fff;
  border: 1px solid #e3e8f0;
}

.settings-nav-item {
  width: 100%;
  border: 0;
  background: transparent;
  color: #526071;
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 9px 10px;
  font-size: 12px;
  font-weight: 700;
  text-align: left;
  cursor: pointer;
}

.settings-nav-item:hover,
.settings-nav-item.active {
  background: #eef4fb;
  color: #31598e;
}

.settings-content {
  min-height: 0;
  overflow-y: auto;
  padding-right: 4px;
}

.settings-section {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.section-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  background: #fff;
  border: 1px solid #e3e8f0;
}

.section-head h2 {
  margin: 2px 0 3px;
  color: #20262e;
  font-size: 18px;
}

.section-head p {
  margin: 0;
  color: #697386;
  font-size: 12px;
  line-height: 1.5;
}

code {
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  padding: 1px 5px;
  font-size: 11px;
}

.settings-grid {
  display: grid;
  gap: 12px;
}

.settings-grid.two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.settings-grid.three { grid-template-columns: repeat(3, minmax(0, 1fr)); }

.setting-card {
  padding: 14px 16px;
  background: #fff;
  border: 1px solid #e3e8f0;
}

.setting-card h3 {
  margin: 0 0 8px;
  color: #28364f;
  font-size: 14px;
}

.setting-card p {
  margin: 0 0 10px;
  color: #697386;
  font-size: 12px;
  line-height: 1.5;
}

.setting-label {
  display: block;
  margin-bottom: 4px;
  color: #7b8daa;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.setting-card strong {
  display: block;
  margin-bottom: 5px;
  color: #20262e;
  font-size: 16px;
  font-weight: 600;
}

.card-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.guide-list { display: flex; flex-direction: column; gap: 12px; }

.guide-card {
  display: grid;
  grid-template-columns: 420px minmax(0, 1fr);
  background: #fff;
  border: 1px solid #e3e8f0;
  overflow: hidden;
}

.guide-image {
  display: flex;
  align-items: flex-start;
  justify-content: center;
  background: #f8fafc;
  border-right: 1px solid #e3e8f0;
  overflow: hidden;
}

.guide-image img {
  width: 100%;
  height: 100%;
  display: block;
  object-fit: cover;
  object-position: top left;
}

.guide-body {
  padding: 16px 18px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.guide-body h3 {
  margin: 0;
  color: #28364f;
  font-size: 15px;
}

.guide-body p {
  margin: 0;
  color: #697386;
  font-size: 12px;
  line-height: 1.5;
}

.guide-title-row {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #31598e;
}

.status-pill {
  padding: 3px 7px;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
}

.status-pill.good { color: #166534; background: #dcfce7; }
.status-pill.neutral { color: #475569; background: #f1f5f9; }
.status-pill.warning { color: #92400e; background: #fef3c7; }
.status-pill.bad { color: #991b1b; background: #fee2e2; }

.info-list {
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.info-list div {
  display: grid;
  grid-template-columns: 130px minmax(0, 1fr);
  gap: 10px;
  padding-bottom: 7px;
  border-bottom: 1px solid #eef2f7;
}

.info-list div:last-child { border-bottom: 0; padding-bottom: 0; }

.info-list dt {
  color: #7b8daa;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.info-list dd {
  margin: 0;
  color: #20262e;
  font-size: 12px;
  word-break: break-word;
}

.settings-list {
  margin: 0;
  padding-left: 16px;
  color: #526071;
  font-size: 12px;
  line-height: 1.65;
}

.danger-soft {
  border-color: #fecaca;
  background: #fffafa;
}

.sync-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.sync-stat {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px 14px;
  border: 1px solid #e2e8f0;
  background: #fff;
}

.sync-value {
  color: #20262e;
  font-size: 12px;
  word-break: break-word;
}

.menu-editor {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.menu-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px;
  border: 1px solid #e2e8f0;
  background: #fff;
}

.menu-row:hover { border-color: #cbd5e1; }

.row-reorder {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.reorder-btn {
  width: 22px;
  height: 16px;
  border: 1px solid #d9dde1;
  background: #f8fafc;
  color: #475569;
  font-size: 8px;
  line-height: 1;
  cursor: pointer;
}

.reorder-btn:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.reorder-btn:not(:disabled):hover { background: #e2e8f0; }

.row-icon {
  width: 26px;
  display: flex;
  justify-content: center;
  color: #475569;
}

.row-field {
  display: flex;
  flex-direction: column;
  gap: 2px;
  flex: 1;
  min-width: 0;
}

.row-field.row-path { flex: 1.4; }
.row-field.row-icon-field { flex: 1.2; }
.row-field.row-toggle { flex: 0 0 52px; }

.row-label {
  color: #6b7280;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.03em;
  text-transform: uppercase;
}

.row-del { flex-shrink: 0; }

.delete-btn {
  border: 1px solid #fecaca;
  background: #fff;
  color: #b91c1c;
  padding: 4px 10px;
  font-size: 11px;
  cursor: pointer;
}

.delete-btn:hover { background: #fef2f2; }

.empty-state {
  padding: 24px;
  text-align: center;
  border: 1px dashed #cbd5e1;
  background: #fff;
}

.empty-title {
  margin: 0 0 4px;
  color: #20262e;
  font-size: 14px;
  font-weight: 600;
}

.empty-desc {
  margin: 0;
  color: #697386;
  font-size: 12px;
}

.feedback-list { display: flex; flex-direction: column; gap: 0; }
.feedback-card { padding: 12px 14px; margin-bottom: 8px; background: #fff; border: 1px solid #e3e8f0; }
.feedback-card-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 6px; }
.feedback-card-left { display: flex; align-items: center; gap: 8px; }
.feedback-card-head time { color: #8b96aa; font-size: 10px; }
.feedback-message { margin: 0; color: #4c5a70; font-size: 12px; line-height: 1.55; }

.category-tag { display: inline-block; padding: 2px 8px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; border: 1px solid transparent; }
.cat-login { color: #1d4ed8; background: #dbeafe; border-color: #bfdbfe; }
.cat-dashboard { color: #15803d; background: #dcfce7; border-color: #bbf7d0; }
.cat-members { color: #7e22ce; background: #f3e8ff; border-color: #e9d5ff; }
.cat-schedules { color: #0e7490; background: #cffafe; border-color: #a5f3fc; }
.cat-attendance { color: #b45309; background: #fef3c7; border-color: #fde68a; }
.cat-kiosk { color: #be185d; background: #fce7f3; border-color: #fbcfe8; }
.cat-default { color: #374151; background: #f3f4f6; border-color: #e5e7eb; }

.rating-tag { display: inline-block; padding: 2px 8px; font-size: 10px; font-weight: 600; border: 1px solid transparent; }
.rt-smooth { color: #15803d; background: #dcfce7; border-color: #bbf7d0; }
.rt-okay { color: #b45309; background: #fef3c7; border-color: #fde68a; }
.rt-needs { color: #dc2626; background: #fee2e2; border-color: #fecaca; }

.feedback-form-modal { padding: 20px; background: #fff; }
.feedback-form-title { margin: 0 0 16px; font-size: 16px; font-weight: 700; color: #1b263b; }
.feedback-form-body { margin-bottom: 16px; }
.feedback-form-foot { display: flex; justify-content: flex-end; gap: 8px; }

.announcement-input-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.announcement-input-row .b-input { flex: 1; }

.announcement-saved {
  display: inline-block;
  margin-top: 6px;
  color: #166534;
  font-size: 11px;
  font-weight: 600;
}

@media (max-width: 1100px) {
  .settings-grid.three,
  .settings-grid.two {
    grid-template-columns: 1fr;
  }

  .guide-card {
    grid-template-columns: 1fr;
  }

  .guide-image {
    border-right: none;
    border-bottom: 1px solid #e3e8f0;
    max-height: 220px;
  }

  .menu-row {
    align-items: stretch;
    flex-wrap: wrap;
  }
}

@media (max-width: 760px) {
  .settings-page { height: auto; min-height: calc(100vh - 56px); overflow: visible; }
  .settings-layout { grid-template-columns: 1fr; }
  .settings-nav { display: flex; overflow-x: auto; overflow-y: hidden; padding: 6px; position: sticky; top: 0; z-index: 5; }
  .settings-nav-item { width: auto; flex: 0 0 auto; white-space: nowrap; }
  .settings-content { overflow: visible; padding-right: 0; }
  .settings-hero,
  .section-head { flex-direction: column; }
}

@media (max-width: 640px) {
  .settings-page { padding: 10px; }
  .settings-hero h1 { font-size: 20px; }
  .settings-hero-actions { flex-wrap: wrap; }
  .menu-row .row-field { width: 100%; }
  .sync-grid { grid-template-columns: 1fr; }
  .info-list div { grid-template-columns: 1fr; gap: 3px; }
  .announcement-input-row { align-items: stretch; flex-direction: column; }
  .feedback-card-head, .feedback-card-left { align-items: flex-start; flex-wrap: wrap; }
  .feedback-form-foot { flex-direction: column-reverse; }
  .feedback-form-foot .button { width: 100%; }
}

@media (max-width: 760px) {
  .settings-shell,
  .settings-layout,
  .settings-section,
  .settings-grid,
  .sync-grid,
  .guide-list {
    gap: 0;
  }

  .settings-hero {
    padding: 11px;
  }

  .settings-hero h1 {
    margin: 1px 0 2px;
    font-size: 17px;
  }

  .settings-hero p,
  .section-head p,
  .setting-card p,
  .guide-body p {
    font-size: 10px;
    line-height: 1.4;
  }

  .settings-nav {
    padding: 3px 0;
    border: 0;
    border-bottom: 1px solid #e5e9ef;
    background: transparent;
  }

  .settings-nav-item {
    min-height: 30px;
    padding: 6px 8px;
    font-size: 10px;
  }

  .section-head,
  .setting-card,
  .sync-stat,
  .menu-row,
  .feedback-card,
  .guide-card {
    padding: 8px 2px;
    border: 0;
    border-bottom: 1px solid #e5e9ef;
    background: transparent;
  }

  .section-head h2 { font-size: 14px; }
  .setting-card h3 { margin-bottom: 5px; font-size: 12px; }
  .setting-card strong { margin-bottom: 2px; font-size: 13px; }

  .info-list { gap: 5px; }
  .info-list div {
    grid-template-columns: 96px minmax(0, 1fr);
    gap: 6px;
    padding-bottom: 5px;
  }

  .info-list dt { font-size: 8px; }
  .info-list dd { font-size: 10px; }

  .menu-row {
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
  }

  .row-label { font-size: 8px; }

  .guide-image {
    max-height: min(42vw, 180px);
    border-bottom: 1px solid #e5e9ef;
  }

  .guide-body { padding: 8px 2px; }

  .empty-state {
    padding: 14px 2px;
    border: 0;
    border-top: 1px solid #e5e9ef;
    background: transparent;
  }

  .announcement-input-row {
    align-items: center;
    flex-direction: row;
  }

  .feedback-card-head,
  .feedback-card-left {
    align-items: center;
  }

  .feedback-form-foot {
    flex-direction: row;
  }

  .feedback-form-foot .button {
    width: auto;
  }
}
</style>
