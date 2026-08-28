<?php

namespace App\Repositories;

use App\Support\CacheHelper;
use Illuminate\Support\Facades\DB;

class BaseRepository
{
    public function find(string $table, $id)
    {
        return DB::table($table)->where('id', $id)->first();
    }

    public function exists(string $table, $id): bool
    {
        return DB::table($table)->where('id', $id)->exists();
    }

    /**
     * Insert a row (with created_by/created_at/updated_at) and return the fresh row.
     */
    public function create(string $table, array $data, array $extra = []): object
    {
        $record = array_merge($data, $extra, [
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = DB::table($table)->insertGetId($record);

        $row = $this->find($table, $id);
        $this->bustCache($table);

        return $row;
    }

    /**
     * Update a row and return the fresh row. Timestamps are added unless disabled.
     */
    public function update(string $table, $id, array $data, array $extra = [], bool $withTimestamp = true): object
    {
        $record = $withTimestamp
            ? array_merge($data, $extra, ['updated_at' => now()])
            : array_merge($data, $extra);

        DB::table($table)->where('id', $id)->update($record);

        $row = $this->find($table, $id);
        $this->bustCache($table);

        return $row;
    }

    public function delete(string $table, $id): void
    {
        DB::table($table)->where('id', $id)->delete();

        $this->bustCache($table);
    }

    /**
     * Cache keys that must be cleared when a table is written to.
     */
    protected function cacheKeysFor(string $table): array
    {
        $map = [
            'member'               => ['dashboard.summary'],
            'attendance_sessions'  => ['dashboard.summary'],
            'attendance_records'   => ['dashboard.summary'],
            'services'             => ['dashboard.summary'],
        ];

        return $map[$table] ?? [];
    }

    protected function bustCache(string $table): void
    {
        foreach ($this->cacheKeysFor($table) as $key) {
            CacheHelper::forget($key);
        }
    }
}
