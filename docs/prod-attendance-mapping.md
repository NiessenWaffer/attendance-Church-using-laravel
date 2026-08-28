# Prod Attendance Mapping

This project currently uses:

- **Members** from the production API
- **Attendance / events / schedules / reports** from the local database

When the app is later pointed to the production attendance database, the local
attendance queries should map to the production tables and columns below.

## Current Rule

- Keep `members` API-driven
- Keep attendance/event/report/history/dashboard database-driven
- Use this file as the source of truth for the future DB switch

## Local -> Prod Table Mapping

| Local table | Purpose | Prod table |
|---|---|---|
| `attendance_events` | attendance sessions/events | `attendance_sessions` |
| `attendance_records` | per-member attendance rows | `attendance_records` |
| `attendance_schedules` | recurring local schedules | `services`, `services_config`, `service_blackouts` |
| `member` | no longer used locally | `members` (already represented by API) |
| `admin_users` | local app login | `admins` |
| `audit_logs` | activity log | `audit_logs` |

## Event / Session Mapping

### Local `attendance_events`

| Local column | Prod column | Notes |
|---|---|---|
| `id` | `attendance_sessions.id` | primary key |
| `event_title` | `attendance_sessions.session_name` | display title |
| `event_date` | `attendance_sessions.session_date` | session date |
| `start_time` | `attendance_sessions.start_time` | start time |
| `event_type` | `attendance_sessions.service_type` | local event type maps best here |
| `remarks` | none direct | optional / derived if needed |
| `is_closed` | `attendance_sessions.status` | local boolean vs prod status string |
| `created_at` | `attendance_sessions.created_at` | timestamp |
| `updated_at` | `attendance_sessions.updated_at` | timestamp |

### Prod-only session fields

These have no direct local equivalent yet:

- `attendance_sessions.end_time`
- `attendance_sessions.service_time`
- `attendance_sessions.schedule_type`
- `attendance_sessions.sync_status`

### Suggested status mapping

| Local `is_closed` | Prod `status` |
|---|---|
| `0` | `scheduled` or `active` |
| `1` | `completed` or `cancelled` |

Note: final mapping depends on how the prod system uses `status` in practice.

## Attendance Record Mapping

### Local `attendance_records`

| Local column | Prod column | Notes |
|---|---|---|
| `id` | `attendance_records.id` | primary key |
| `event_id` | `attendance_records.session_id` | foreign key to session |
| `member_id` | `attendance_records.member_id` | prod likely uses numeric member id |
| `member_code` | none direct in record table | resolve through joined `members.member_code` |
| `attendance_status` | derived / app-level mapping | prod table does not show this column |
| `check_in_time` | `attendance_records.check_in_time` | direct match |
| `remarks` | `attendance_records.notes` | direct semantic match |
| `created_at` | `attendance_records.created_at` | direct match |

### Prod-only record fields

- `attendance_records.sync_id`
- `attendance_records.attendance_date`
- `attendance_records.service_time`
- `attendance_records.check_out_time`
- `attendance_records.sync_status`

### Important mismatch

Local attendance currently stores explicit statuses:

- `present`
- `late`
- `absent`
- `excused`

The posted prod `attendance_records` schema does **not** include an
`attendance_status` column.

That means one of these is true:

1. the prod app derives status from check-in/check-out and session rules, or
2. there is another table/column not listed yet, or
3. the local app's status model will need to be simplified when switching

This is the main unresolved attendance-schema gap.

## Member Mapping

The local app no longer uses the local `member` table, but these fields matter
for joins and hydration when the production DB is introduced.

### Prod `members`

Important fields already confirmed:

- `id`
- `member_code`
- `short_code`
- `first_name`
- `last_name`
- `email`
- `phone_number`
- `status`
- `profile_photo_url`
- `ministries`
- `external_source`
- `external_id`

### Recommended identity lookup priority

When resolving a member reference from prod attendance input:

1. `member_code` (`WOH-XXXX`)
2. `short_code`
3. numeric `id`

This matches the prod endpoints you provided.

## Schedule Mapping

Local recurring schedules likely map across multiple prod tables:

| Local concept | Prod table | Notes |
|---|---|---|
| schedule definition | `services` | primary service definition |
| repeating day/time config | `services_config` | normalized schedule rows |
| blackout dates | `service_blackouts` | skip/cancel specific dates |

This means local `attendance_schedules` is flatter than prod and will probably
need query composition rather than a 1:1 table rename.

## Audit / Admin / Other Tables

### Audit

| Local | Prod |
|---|---|
| `audit_logs.user_id` | `audit_logs.user_id` |
| `audit_logs.user_name` | `audit_logs.user_name` |
| `audit_logs.action` | `audit_logs.action` |
| `audit_logs.module` | `audit_logs.module` |
| `audit_logs.details` | `audit_logs.details` |
| `audit_logs.ip_address` | `audit_logs.ip_address` |
| `audit_logs.created_at` | `audit_logs.created_at` |

Prod adds:

- `audit_logs.sync_status`

### Admins

| Local | Prod |
|---|---|
| `admin_users.id` | `admins.id` |
| `admin_users.email` | `admins.email` |
| `admin_users.password` | `admins.password_hash` |
| `admin_users.full_name` | `admins.display_name` |

Prod adds:

- `role`
- `google_sub`
- `google_email`
- `password_updated_at`
- `theme_preference`

## Practical Switch Plan

When the production DB connection is available, update in this order:

1. Add a dedicated production DB connection in Laravel config
2. Replace attendance session reads:
   - `attendance_events` -> `attendance_sessions`
3. Replace attendance record reads:
   - `attendance_records.event_id` -> `attendance_records.session_id`
4. Rework record hydration joins:
   - join prod `members` by `member_id`
   - still expose `member_code` and `short_code`
5. Decide how prod represents attendance status
6. Replace schedule generation logic with prod `services` + `services_config`
7. Re-test dashboard, history, reports, follow-ups, and PDF export

## Current Non-Negotiables

Until the prod DB is actually wired:

- `Members` page stays API-based
- `Attendance`, `History`, `Reports`, `Dashboard`, `Schedules` stay local DB-based
- Do **not** switch attendance flow to prod API endpoints yet
