<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BirthdayService
{
    public function monthCelebrants(): array
    {
        $month = Carbon::now()->month;
        $members = app(MemberFetchService::class)->list();

        $byCode = [];
        foreach ($members as $member) {
            $code = (string) ($member['member_code'] ?? '');
            if ($code !== '') {
                $byCode[$code] = $member;
            }
        }

        $celebrants = [];

        $localBirthdays = DB::table('member_birthdays')->get();

        foreach ($localBirthdays as $row) {
            try {
                $birth = Carbon::parse($row->birth_date);
            } catch (\Throwable $e) {
                continue;
            }

            if ($birth->month !== $month) {
                continue;
            }

            $code = (string) $row->member_code;
            $external = $byCode[$code] ?? null;

            $celebrants[$code] = [
                'member_code' => $code,
                'name' => $external
                    ? trim(($external['first_name'] ?? '') . ' ' . ($external['last_name'] ?? ''))
                    : null,
                'day' => (int) $birth->day,
            ];
        }

        foreach ($members as $member) {
            $code = (string) ($member['member_code'] ?? '');
            if ($code === '' || isset($celebrants[$code])) {
                continue;
            }

            $birthDate = $member['birth_date'] ?? null;
            if (!$birthDate) {
                continue;
            }

            try {
                $birth = Carbon::parse($birthDate);
            } catch (\Throwable $e) {
                continue;
            }

            if ($birth->month !== $month) {
                continue;
            }

            $celebrants[$code] = [
                'member_code' => $code,
                'name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
                'day' => (int) $birth->day,
            ];
        }

        $celebrants = array_values($celebrants);

        usort($celebrants, function ($a, $b) { return $a['day'] <=> $b['day']; });

        return [
            'birthdays' => array_slice($celebrants, 0, 30),
            'month_label' => Carbon::now()->format('F'),
        ];
    }

    public function checkFor(array $member, string $scannedCode): ?array
    {
        $raw = $member['member'] ?? [];
        $canonical = $member['external_member_id'] ?? null;

        $localBirthDate = DB::table('member_birthdays')
            ->where('member_code', $canonical)
            ->orWhere('member_code', $scannedCode)
            ->value('birth_date');

        $birthDate = $localBirthDate
            ?: $member['birth_date']
            ?? $raw['birth_date']
            ?? (is_array($raw['personal_information'] ?? null)
                ? ($raw['personal_information']['birth_date'] ?? null)
                : null);

        if (!$birthDate) {
            return null;
        }

        try {
            $birth = Carbon::parse($birthDate);
        } catch (\Throwable $e) {
            return null;
        }

        $today = Carbon::today();
        if ($birth->month !== $today->month) {
            return null;
        }

        return [
            'name' => $member['name'] ?: trim(($raw['first_name'] ?? '') . ' ' . ($raw['last_name'] ?? '')),
            'age' => $birth->age,
            'photo' => $member['profile_photo_url'] ?? null,
            'message' => 'May your new year be filled with joy, grace, and beautiful memories.',
        ];
    }
}
