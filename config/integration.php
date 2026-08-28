<?php

return [

    /*
    |--------------------------------------------------------------------------
    | External Member Integration
    |--------------------------------------------------------------------------
    |
    | The external system stays the source of truth. This app only READS the
    | member list from the external endpoint (on demand, cached briefly) and
    | never copies external members into the local `member` table. Attendance
    | records reference external members by member_code (member_id stays null
    | for them); their names are resolved at read time by MemberFetchService.
    |
    | Configure INTEGRATION_URL and INTEGRATION_KEY in your .env file.
    |
    | Production endpoint (GET):
    |   https://attendance.wohcaloocan.org/api/v1/members
    |   Authorization: Bearer <INTEGRATION_KEY>
    |
    | Response shape:
    |   { "success": true, "message": "...", "data": [ ...members... ],
    |     "pagination": { "current_page": 1, "per_page": 20, "total": 92, "total_pages": 5 } }
    |
    */

    'url' => env('INTEGRATION_URL', ''),

    'key' => env('INTEGRATION_KEY', ''),

    'growth_track_base_url' => env('GROWTH_TRACK_BASE_URL', preg_replace('#/members$#i', '', env('INTEGRATION_URL', ''))),
    'growth_track_key' => env('GROWTH_TRACK_API_KEY', env('INTEGRATION_KEY', '')),

    'timeout' => (int) env('INTEGRATION_TIMEOUT', 10),

    /*
    | Where the member list lives inside the JSON response.
    | Supports dot notation, e.g. "data.members".
    */
    'members_path' => 'data',

    /*
    | Map external JSON keys to `member` table columns.
    | Only keys present here are carried through; scalar values only.
    */
    'mapping' => [
        'external_id'      => 'id',
        'member_code'       => 'member_id',        // e.g. "WOH-T3RF"
        'first_name'        => 'first_name',
        'last_name'         => 'last_name',
        'email'             => 'email',
        'mobile_number'     => 'phone_number',
        'membership_status' => 'status',           // e.g. "active"
        'date_joined'       => 'created_at',       // "2026-08-02 11:01:05"
        'ministries'        => 'ministries',       // string OR array of strings
    ],

    /*
    | Pagination. The production endpoint returns 20 members per page.
    | MemberFetchService reads total_pages from the first page and follows it.
    */
    'pagination' => [
        'enabled'            => true,
        'query_param'        => 'page',
        'total_pages_path'   => 'pagination.total_pages',
        'max_pages'          => 20,
    ],

    /*
    | Sample payload for reference / testing the mapping.
    */
    'sample_payload' => [
        'success' => true,
        'message' => 'Members retrieved successfully.',
        'data' => [
            [
                'member_id'    => 'WOH-T3RF',
                'short_code'   => 'HJXGTN',
                'first_name'   => 'Elizabeth',
                'last_name'    => 'Candolita',
                'email'        => '',
                'phone_number' => '',
                'status'       => 'active',
                'ministries'   => ['Praise and Worship Ministry', 'Youth Alive Ministry'],
                'created_at'   => '2026-08-02 11:01:05',
            ],
            [
                'member_id'    => 'WOH-EJB8',
                'short_code'   => 'QK9MNP',
                'first_name'   => 'Roberto',
                'last_name'    => 'Mellendrez',
                'email'        => '',
                'phone_number' => '',
                'status'       => 'active',
                'ministries'   => 'Pastoral Staff',
                'created_at'   => '2026-08-02 11:01:05',
            ],
        ],
        'pagination' => [
            'current_page' => 1,
            'per_page'     => 20,
            'total'        => 92,
            'total_pages'  => 5,
        ],
    ],

];
