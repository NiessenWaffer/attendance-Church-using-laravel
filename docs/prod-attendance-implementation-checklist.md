# Prod Attendance Implementation Checklist

This checklist turns the prod attendance schema mismatch into a concrete
execution plan.

Use this only when switching attendance/event/schedule data to the production
database schema. Until then:

- members stay API-driven
- attendance/events/history/reports/dashboard stay local DB-driven

## Phase 1: Confirm Missing Prod Behavior

### 1. Attendance status source

- [ ] Confirm where prod stores attendance status
- [ ] Determine whether prod supports these exact states:
  - [ ] `present`
  - [ ] `late`
  - [ ] `absent`
  - [ ] `excused`
- [ ] If no direct column exists, define derived rules from:
  - [ ] `check_in_time`
  - [ ] `check_out_time`
  - [ ] `attendance_sessions.start_time`
  - [ ] `attendance_sessions.end_time`
  - [ ] `attendance_sessions.status`

### 2. Sample data validation

- [ ] Get sample rows from prod `attendance_sessions`
- [ ] Get sample rows from prod `attendance_records`
- [ ] Get sample rows from prod `services`
- [ ] Get sample rows from prod `services_config`
- [ ] Get sample rows from prod `service_blackouts`
- [ ] Confirm whether `ministries` is JSON, text, or comma-separated in prod DB

## Phase 2: Add Prod DB Connection

- [ ] Add dedicated Laravel DB connection for prod attendance DB
- [ ] Keep current local DB connection intact
- [ ] Make the connection name configurable via `.env`
- [ ] Verify read-only queries work before any write logic changes

Suggested env keys:

- [ ] `PROD_DB_HOST`
- [ ] `PROD_DB_PORT`
- [ ] `PROD_DB_DATABASE`
- [ ] `PROD_DB_USERNAME`
- [ ] `PROD_DB_PASSWORD`

## Phase 3: Replace Event Reads With Session Reads

### Backend

- [ ] Replace all `attendance_events` read queries with `attendance_sessions`
- [ ] Map columns:
  - [ ] `event_title` -> `session_name`
  - [ ] `event_date` -> `session_date`
  - [ ] `event_type` -> `service_type`
  - [ ] `is_closed` -> `status`
- [ ] Add support for prod-only fields:
  - [ ] `end_time`
  - [ ] `service_time`
  - [ ] `schedule_type`
  - [ ] `sync_status`

### Files to change

- [ ] `app/Http/Controllers/AttendanceEventController.php`
- [ ] `app/Http/Controllers/DashboardController.php`
- [ ] `app/Http/Controllers/ReportController.php`
- [ ] `app/Support/AttendanceQuery.php`

## Phase 4: Replace Attendance Record Reads/Writes

### Backend

- [ ] Replace `attendance_records.event_id` usage with `attendance_records.session_id`
- [ ] Replace `remarks` with `notes`
- [ ] Add prod fields where needed:
  - [ ] `attendance_date`
  - [ ] `service_time`
  - [ ] `check_out_time`
  - [ ] `sync_id`
  - [ ] `sync_status`
- [ ] Remove local dependency on `member_code` in the record table itself
- [ ] Join or hydrate `member_code` from prod `members`

### Write-path decision

- [ ] Decide whether writes go directly to prod DB
- [ ] Or stay local and sync later

### Files to change

- [ ] `app/Http/Controllers/AttendanceRecordController.php`
- [ ] `app/Support/AttendanceQuery.php`
- [ ] `app/Services/MemberFetchService.php` (if record hydration changes)

## Phase 5: Resolve Attendance Status Model

This is the main blocker.

- [ ] If prod has a real status column, wire it into the app
- [ ] If prod does not, redefine app logic for:
  - [ ] dashboard analytics
  - [ ] report summaries
  - [ ] history filters
  - [ ] attendance roster dropdown
- [ ] Decide whether local UI still needs:
  - [ ] `late`
  - [ ] `absent`
  - [ ] `excused`

### Files blocked by this decision

- [ ] `app/Http/Controllers/DashboardController.php`
- [ ] `app/Http/Controllers/ReportController.php`
- [ ] `app/Http/Controllers/AttendanceRecordController.php`
- [ ] `resources/js/pages/Attendance.vue`
- [ ] `resources/js/pages/History.vue`
- [ ] `resources/js/pages/Report.vue`

## Phase 6: Replace Schedule Layer

Local `attendance_schedules` does not match prod.

### Prod schedule model

- [ ] `services`
- [ ] `services_config`
- [ ] `service_blackouts`

### Work required

- [ ] Replace one-table local schedule assumptions
- [ ] Map local schedule creation/edit UI to prod service/service_config model
- [ ] Add blackout handling
- [ ] Rework schedule generation logic completely

### Files to change

- [ ] `app/Http/Controllers/AttendanceScheduleController.php`
- [ ] `app/Services/ScheduleService.php`
- [ ] `resources/js/pages/Attendance.vue` (events + attendance merged; generate flow lives here)
- [ ] schedule-related Vue components/forms

## Phase 7: Frontend Session Field Renames

- [ ] Replace `event_title` display with `session_name`
- [ ] Replace `event_date` display with `session_date`
- [ ] Replace `event_type` with `service_type`
- [ ] Replace close/open UI if prod uses richer `status`
- [ ] Replace session display/form fields to prod session field names if local session management is enabled
- [ ] Add `end_time`
- [ ] Add `service_time`
- [ ] Add `schedule_type` if needed
- [ ] Redefine status UI from boolean close/open to prod status values

### Files to change

- [ ] `resources/js/pages/Attendance.vue` (events + attendance merged)
- [ ] `resources/js/pages/Schedules.vue`

## Phase 8: Dashboard / Report / History Analytics

- [ ] Rework latest/recent event queries against `attendance_sessions`
- [ ] Rework trend queries
- [ ] Rework monthly aggregation
- [ ] Rework follow-up logic if attendance status changes
- [ ] Rework report groupings and PDF export fields

### Files to change

- [ ] `app/Http/Controllers/DashboardController.php`
- [ ] `app/Http/Controllers/ReportController.php`
- [ ] `app/Support/AttendanceQuery.php`
- [ ] `resources/js/pages/Dashboard.vue`
- [ ] `resources/js/pages/History.vue`
- [ ] `resources/js/pages/Report.vue`
- [ ] `resources/views/reports/attendance.blade.php`

## Phase 9: Audit / Admin Optional Parity

Only do this if local auth/logging should also match prod.

### Audit

- [ ] Map local `audit_logs.username` -> prod `audit_logs.user_name`
- [ ] Map local `description` -> prod `details`
- [ ] Decide how to handle local `user_agent` / `metadata`
- [ ] Add `sync_status` if needed

### Admin

- [ ] Map local `admin_users` -> prod `admins`
- [ ] Map `password` -> `password_hash`
- [ ] Map `full_name` -> `display_name`
- [ ] Decide whether to support `role`
- [ ] Decide whether to support Google login fields

## Phase 10: Verification Checklist

- [ ] `php -l` all changed PHP files
- [ ] rebuild frontend bundle
- [ ] test member hydration against prod members API
- [ ] test attendance list
- [ ] test attendance save/update
- [ ] test history filtering
- [ ] test report summary
- [ ] test PDF export
- [ ] test dashboard analytics
- [ ] test schedule create/edit/generate

## Recommended Build Order

1. Confirm attendance status behavior
2. Add prod DB connection
3. Convert event/session reads
4. Convert attendance record reads/writes
5. Convert dashboard/history/report analytics
6. Convert schedule system
7. Optional: convert audit/admin parity

## Current Blockers

These must be answered before a full match is possible:

- [ ] Where is prod attendance status stored?
- [ ] Does prod support `late`, `absent`, and `excused`?
- [ ] What is the exact DB type/shape of `ministries` in prod tables?
- [ ] Should writes happen directly to prod DB or remain local with sync?
