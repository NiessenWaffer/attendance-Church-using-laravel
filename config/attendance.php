<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Attendance Schema Mode
    |--------------------------------------------------------------------------
    |
    | local: scanner-compatible local tables (`services`,
    |        `attendance_sessions`, `attendance_records`)
    | prod : production attendance tables (`attendance_sessions`,
    |        `attendance_records`, `services` / `services_config` / blackouts)
    |
    | Keep this on `local` until the prod attendance DB is actually wired and
    | the remaining schema gaps (especially attendance status) are resolved.
    |
    */

    'mode' => env('ATTENDANCE_SCHEMA_MODE', 'local'),

    /*
    | DB connection used for attendance/session/schedule data.
    */
    'connection' => env('ATTENDANCE_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),

    /*
    | How many minutes after a session's start_time a check-in still counts as
    | on time. Used only when the attendance status is derived (prod mode).
    */
    'late_grace_minutes' => (int) env('ATTENDANCE_LATE_GRACE_MINUTES', 15),

    'kiosk_key' => env('ATTENDANCE_KIOSK_KEY', ''),

    'tables' => [
        'local' => [
            'sessions'  => 'attendance_sessions',
            'records'   => 'attendance_records',
            'services'  => 'services',
            'blackouts' => 'service_blackouts',
        ],
        'prod' => [
            'sessions'  => 'attendance_sessions',
            'records'   => 'attendance_records',
            'services'  => 'services',
            'configs'   => 'services_config',
            'blackouts' => 'service_blackouts',
        ],
    ],

    'columns' => [
        'local' => [
            'session_title'        => 'session_name',
            'session_date'         => 'session_date',
            'session_type'         => 'service_type',
            'session_status'       => 'status',
            'record_session_id'    => 'session_id',
            'record_notes'         => null,
            'record_status'        => null,
            'record_member_id'     => 'external_member_id',
            'record_member_code'   => 'external_member_id',
            'record_check_in'      => 'created_at',
        ],
        'prod' => [
            'session_title'        => 'session_name',
            'session_date'         => 'session_date',
            'session_type'         => 'service_type',
            'session_status'       => 'status',
            'record_session_id'    => 'session_id',
            'record_notes'         => 'notes',
            'record_status'        => null,
            'record_member_id'     => 'member_id',
            'record_member_code'   => null,
            'record_check_in'      => 'created_at',
        ],
    ],
];
