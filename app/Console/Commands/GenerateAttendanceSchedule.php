<?php

namespace App\Console\Commands;

use App\Services\ScheduleService;
use Illuminate\Console\Command;

class GenerateAttendanceSchedule extends Command
{
    protected $signature = 'attendance:generate-schedule {--date=} {--from=} {--to=}';

    protected $description = 'Generate attendance sessions from active schedules.';

    public function handle(ScheduleService $service)
    {
        if ($this->option('from') && $this->option('to')) {
            $created = $service->generateRange($this->option('from'), $this->option('to'));
        } else {
            $created = $service->generateForDate($this->option('date'));
        }

        $this->info("Generated {$created} session(s).");
    }
}
