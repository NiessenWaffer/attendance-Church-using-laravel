<?php

namespace App\Services;

use App\Services\MemberFetchService;
use Carbon\Carbon;

class DashboardReminderService
{
    public function upcoming(): array
    {
        $start = Carbon::today();
        $end = Carbon::today()->addDays(7)->endOfDay();
        $birthdays = [];
        $anniversaries = [];

        $members = app(MemberFetchService::class)->list();

        foreach ($members as $member) {
            $name = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
            $this->addReminder($birthdays, $member, $member['birth_date'] ?? null, $start, $end, 'age', 'birthday', $name);
            $this->addReminder($anniversaries, $member, $member['date_joined'] ?? null, $start, $end, 'years', 'anniversary', $name);
        }

        return [
            'birthdays' => $this->sortAndLimit($birthdays),
            'anniversaries' => $this->sortAndLimit($anniversaries),
        ];
    }

    private function addReminder(array &$reminders, $member, $sourceDate, Carbon $start, Carbon $end, string $countKey, string $type, string $name): void
    {
        if (!$sourceDate) {
            return;
        }

        try {
            $original = Carbon::parse($sourceDate)->startOfDay();
        } catch (\Throwable $e) {
            return;
        }

        if ($original->year < 1 || $original->year > $start->year) {
            return;
        }

        $next = $this->occurrenceInYear($original, $start->year);

        if ($next->lt($start)) {
            $next = $this->occurrenceInYear($original, $start->year + 1);
        }

        if (!$next->between($start, $end)) {
            return;
        }

        $reminders[] = [
            'id' => null,
            'name' => $name,
            'member_code' => $member['member_code'] ?? null,
            'date' => $next->format('Y-m-d'),
            'type' => $type,
            $countKey => $next->year - $original->year,
        ];
    }

    private function occurrenceInYear(Carbon $original, int $year): Carbon
    {
        $day = min($original->day, Carbon::create($year, $original->month, 1)->daysInMonth);

        return Carbon::create($year, $original->month, $day)->startOfDay();
    }

    private function sortAndLimit(array $reminders): array
    {
        usort($reminders, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        return array_slice($reminders, 0, 20);
    }
}
