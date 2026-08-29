<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Attendance Report — Word of Hope Caloocan</title>
    <style>
        @page { margin: 10mm 16mm 14mm 16mm; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9.5pt;
            color: #1a1a1a;
            line-height: 1.4;
        }

        /* Header */
        .header { padding-bottom: 5px; border-bottom: 2px solid #111; margin-bottom: 6px; }
        .church-name { font-size: 14pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.06em; }
        .report-title { font-size: 9pt; color: #555; margin-top: 2px; }
        .header-meta { text-align: right; font-size: 8pt; color: #777; }

        /* Meta line */
        .meta-line {
            font-size: 7.5pt;
            color: #555;
            padding: 4px 0;
            border-bottom: 1px solid #ccc;
            margin-bottom: 8px;
        }
        .meta-line strong { color: #222; }

        /* Section */
        .section {
            margin-bottom: 10px;
        }
        .section-title {
            font-size: 9pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding-bottom: 2px;
            margin-bottom: 4px;
        }
        .section-note {
            font-size: 7.5pt;
            color: #666;
            margin-bottom: 4px;
        }

        /* Summary row */
        .summary-row {
            margin-bottom: 4px;
        }
        .summary-row td {
            padding: 2px 0;
            vertical-align: bottom;
        }
        .summary-row .sr-pair {
            padding: 0 10px 0 0;
            white-space: nowrap;
        }
        .summary-row .sr-pair:first-child {
            padding-left: 0;
        }
        .summary-row .sr-label {
            font-size: 7pt;
            text-transform: uppercase;
            color: #777;
            letter-spacing: 0.04em;
        }
        .summary-row .sr-value {
            font-size: 9.5pt;
            font-weight: 600;
            color: #222;
        }

        /* Data rows - borderless, clean alignment */
        .clean-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        .clean-table th {
            font-size: 6.5pt;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #555;
            text-align: left;
            padding: 2px 0;
            border-bottom: 1px solid #999;
            font-weight: bold;
        }
        .clean-table th.r { text-align: right; }
        .clean-table td {
            padding: 2px 0;
            font-size: 8pt;
            border-bottom: 1px solid #e5e5e5;
            vertical-align: bottom;
        }
        .clean-table td.r { text-align: right; }
        .clean-table td b { font-weight: bold; }
        .scan-log th { font-size: 6pt; }
        .scan-log td { font-size: 7pt; padding: 1.5px 0; }

        .empty-note {
            font-size: 7.5pt;
            color: #999;
            font-style: italic;
            padding: 2px 0;
        }

        /* Footer */
        .footer {
            margin-top: 20px;
            padding-top: 6px;
            border-top: 1px solid #ccc;
            font-size: 7.5pt;
            color: #999;
        }
        .fl { float: left; }
        .fr { float: right; }
        .cb { clear: both; }
    </style>
</head>
<body>

    <!-- Header -->
    <table class="header" style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="width:48px; vertical-align:middle;">
                @if(file_exists(public_path('images/WOHLOGO.png')))
                    <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/WOHLOGO.png'))) }}" style="width:40px; height:40px;" alt="Logo" />
                @endif
            </td>
            <td style="padding-left:10px; vertical-align:middle;">
                <div class="church-name">Word of Hope Caloocan</div>
                <div class="report-title">Attendance &amp; Participation Report</div>
            </td>
            <td class="header-meta" style="vertical-align:top;">
                Official Report<br>{{ now()->format('Y-m-d') }}
            </td>
        </tr>
    </table>

    <!-- Meta -->
    <div class="meta-line">
        <strong>Range:</strong>
        @if($dateFrom || $dateTo)
            {{ $dateFrom ?: 'Start' }} &mdash; {{ $dateTo ?: 'Today' }}
        @else
            All History
        @endif
        &nbsp;&nbsp;&middot;&nbsp;&nbsp;
        <strong>Ministry:</strong> {{ $ministry ?: 'All' }}
        &nbsp;&nbsp;&middot;&nbsp;&nbsp;
        <strong>Generated:</strong> {{ now()->format('F j, Y g:i A') }}
    </div>

    <!-- 1. Sunday Attendance Health -->
    @php
        $sOverview = $sunday['overview'] ?? [];
        $sRows = $sunday['rows'] ?? [];
    @endphp

    <div class="section">
        <div class="section-title">1. Sunday Attendance Health</div>
        <div class="section-note">A member is counted present if they scan in any Sunday service on that date.</div>

        <table class="summary-row" style="width:100%; border-collapse:collapse;">
            <tr>
                <td class="sr-pair"><span class="sr-label">Active Members </span><span class="sr-value">{{ $sOverview['active_members'] ?? 0 }}</span></td>
                <td class="sr-pair"><span class="sr-label">Unique Attendee-Days </span><span class="sr-value">{{ $sOverview['unique_attendee_days'] ?? 0 }}</span></td>
                <td class="sr-pair"><span class="sr-label">Service Participations </span><span class="sr-value">{{ $sOverview['service_participations'] ?? 0 }}</span></td>
                <td class="sr-pair"><span class="sr-label">Missed </span><span class="sr-value">{{ $sOverview['missed'] ?? 0 }}</span></td>
                <td class="sr-pair"><span class="sr-label">Sundays </span><span class="sr-value">{{ $sOverview['sundays'] ?? 0 }}</span></td>
                <td class="sr-pair"><span class="sr-label">Health Rate </span><span class="sr-value">{{ $sOverview['rate'] ?? 0 }}%</span></td>
            </tr>
        </table>

        <table class="clean-table">
            <thead>
                <tr>
                    <th>Sunday Date</th>
                    <th class="r">Unique Attendees</th>
                    <th class="r">Service Participations</th>
                    <th class="r">Missed</th>
                    <th class="r">Rate</th>
                </tr>
            </thead>
            <tbody>
                @forelse($sRows as $row)
                    <tr>
                        <td><b>{{ $row['date'] }}</b></td>
                        <td class="r">{{ $row['present'] }}</td>
                        <td class="r">{{ $row['service_participations'] }}</td>
                        <td class="r">{{ $row['missed'] }}</td>
                        <td class="r"><b>{{ $row['rate'] }}%</b></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty-note">No Sunday records in range.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- 2. Youth Participation -->
    @php
        $yOverview = $youth['overview'] ?? [];
        $yRows = $youth['rows'] ?? [];
    @endphp

    <div class="section">
        <div class="section-title">2. Youth Gathering &amp; Worship Participation</div>

        <table class="summary-row" style="width:100%; border-collapse:collapse;">
            <tr>
                <td class="sr-pair"><span class="sr-label">Youth Members </span><span class="sr-value">{{ $yOverview['youth_members'] ?? 0 }}</span></td>
                <td class="sr-pair"><span class="sr-label">Attended Youth </span><span class="sr-value">{{ $yOverview['attended_youth'] ?? 0 }}</span></td>
                <td class="sr-pair"><span class="sr-label">Worship Only </span><span class="sr-value">{{ $yOverview['worship_only'] ?? 0 }}</span></td>
                <td class="sr-pair"><span class="sr-label">No Scan </span><span class="sr-value">{{ $yOverview['no_sunday_scan'] ?? 0 }}</span></td>
            </tr>
        </table>

        <table class="clean-table">
            <thead>
                <tr>
                    <th>Sunday Date</th>
                    <th class="r">Attended Youth</th>
                    <th class="r">Worship Only</th>
                    <th class="r">No Scan</th>
                </tr>
            </thead>
            <tbody>
                @forelse($yRows as $row)
                    <tr>
                        <td><b>{{ $row['date'] }}</b></td>
                        <td class="r">{{ $row['attended_youth'] }}</td>
                        <td class="r">{{ $row['worship_only'] }}</td>
                        <td class="r">{{ $row['no_sunday_scan'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty-note">No youth data in range.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- 3. Service Breakdown -->
    <div class="section">
        <div class="section-title">3. Service Breakdown</div>

        <table class="clean-table">
            <thead>
                <tr>
                    <th>Service</th>
                    <th>Date</th>
                    <th class="r">Participants</th>
                    <th class="r">Expected / Audience</th>
                    <th class="r">Guest / Other</th>
                    <th class="r">Audience Rate</th>
                </tr>
            </thead>
            <tbody>
                @forelse($bySession as $session)
                    <tr>
                        <td><b>{{ $session->session_title }}</b></td>
                        <td>{{ $session->session_date }}</td>
                        <td class="r">{{ $session->participation_count }}</td>
                        <td class="r"><b>{{ $session->expected_present_count }} / {{ $session->eligible_member_count }}</b></td>
                        <td class="r">{{ $session->guest_other_count }}</td>
                        <td class="r">{{ $session->attendance_rate }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-note">No services in range.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- 4. Detailed Scan Log -->
    @if(count($records) > 0)
    <div class="section">
        <div class="section-title">4. Attendance Scan Log</div>

        <table class="clean-table scan-log">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Service</th>
                    <th>Member</th>
                    <th>Code</th>
                    <th>Status</th>
                    <th class="r">Time</th>
                </tr>
            </thead>
            <tbody>
                @foreach($records as $record)
                    <tr>
                        <td>{{ $record->session_date }}</td>
                        <td>{{ $record->session_title }}</td>
                        <td>{{ trim(($record->first_name ?? '') . ' ' . ($record->last_name ?? '')) ?: ($record->member_name_cache ?: '-') }}</td>
                        <td>{{ $record->member_code ?: $record->member_id }}</td>
                        <td>{{ ucfirst($record->attendance_status ?? 'present') }}</td>
                        <td class="r">{{ $record->check_in_time ? \Carbon\Carbon::parse($record->check_in_time)->format('h:i A') : '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <!-- Footer -->
    <div class="footer">
        <span class="fl">Word of Hope Caloocan &mdash; Attendance System</span>
        <span class="fr">{{ now()->format('Y-m-d H:i:s') }}</span>
        <span class="cb"></span>
    </div>

</body>
</html>
