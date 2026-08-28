<?php

namespace App\Services;

use App\Support\AttendanceSchema;
use App\Support\CacheHelper;
use Illuminate\Database\QueryException;

class AttendanceRecordService
{
    public function insert($session, string $memberCode, ?array $member = null, array $offline = []): array
    {
        $schema = app(AttendanceSchema::class);
        $db = $schema->db();
        $table = $schema->recordsTable();
        $canonicalMemberId = $this->canonicalMemberIdentifier($memberCode, $member);

        if ($canonicalMemberId === null) {
            throw new \InvalidArgumentException('The external member identifier was not resolved exactly.');
        }

        $data = $schema->buildRecordArray($session, $canonicalMemberId, $member);
        if (array_key_exists('sync_id', $offline) && $schema->hasColumn($table, 'sync_id')) {
            $data['sync_id'] = $offline['sync_id'];
        }
        if (array_key_exists('offline_created_at', $offline) && $schema->hasColumn($table, 'offline_created_at')) {
            $data['offline_created_at'] = $offline['offline_created_at'];
        }

        try {
            $result = $db->transaction(function () use ($db, $schema, $table, $session, $canonicalMemberId, $offline, $data) {
                $db->table($schema->sessionsTable())->where('id', $session->id)->lockForUpdate()->first();
                $existing = $this->findExisting(
                    (int) $session->id,
                    $canonicalMemberId,
                    $offline['sync_id'] ?? null
                );

                if ($existing) {
                    return ['id' => $existing->id, 'duplicate' => true, 'existing' => $existing];
                }

                return ['id' => $db->table($table)->insertGetId($data), 'duplicate' => false];
            });
            $this->invalidateDashboardCaches();

            return $result;
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
                $existing = $this->findExisting(
                    (int) $session->id,
                    $canonicalMemberId,
                    $offline['sync_id'] ?? null
                );

                if ($existing) {
                    return [
                        'id' => $existing->id,
                        'duplicate' => true,
                        'existing' => $existing,
                    ];
                }
            }

            throw $e;
        }
    }

    public function canonicalMemberIdentifier(string $identifier, ?array $member): ?string
    {
        $identifier = trim($identifier);
        if ($identifier === '' || !$member) {
            return null;
        }

        $rawMember = is_array($member['member'] ?? null) ? $member['member'] : [];
        $rawHasIdentifier = false;
        foreach (['external_member_id', 'member_code', 'barcode', 'id', 'member_id', 'external_id'] as $field) {
            if (isset($rawMember[$field]) && is_scalar($rawMember[$field]) && trim((string) $rawMember[$field]) !== '') {
                $rawHasIdentifier = true;
                break;
            }
        }

        $accepted = [];

        foreach ([$member, $rawMember] as $source) {
            foreach (['external_member_id', 'member_code', 'barcode', 'id'] as $field) {
                if ($source === $member && $field === 'external_member_id' && !$rawHasIdentifier) {
                    continue;
                }
                if (isset($source[$field]) && is_scalar($source[$field])) {
                    $value = trim((string) $source[$field]);
                    if ($value !== '') {
                        $accepted[] = $value;
                    }
                }
            }
        }

        $matched = false;
        foreach ($accepted as $value) {
            if (strcasecmp($value, $identifier) === 0) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            return null;
        }

        $schema = app(AttendanceSchema::class);
        $storageFields = $schema->recordMemberCodeColumn() !== null
            ? ['member_code', 'external_member_id', 'barcode', 'id']
            : ['id', 'external_id', 'member_id', 'external_member_id'];

        foreach ($storageFields as $field) {
            foreach ([$rawMember, $member] as $source) {
                if (isset($source[$field]) && is_scalar($source[$field])) {
                    $value = trim((string) $source[$field]);
                    if ($value !== '') return $value;
                }
            }
        }

        return null;
    }

    public function findBySyncId(string $syncId)
    {
        $schema = app(AttendanceSchema::class);

        if (!$schema->hasColumn($schema->recordsTable(), 'sync_id')) {
            return null;
        }

        return $schema->db()->table($schema->recordsTable())
            ->where('sync_id', $syncId)
            ->first();
    }

    private function findExisting(int $sessionId, string $memberCode, ?string $syncId = null)
    {
        $schema = app(AttendanceSchema::class);
        $table = $schema->recordsTable();
        $query = $schema->db()->table($table);

        if ($syncId !== null && $syncId !== '' && $schema->hasColumn($table, 'sync_id')) {
            $existing = (clone $query)->where('sync_id', $syncId)->first();
            if ($existing) {
                return $existing;
            }
        }

        $memberColumn = $schema->recordMemberCodeColumn() ?: $schema->recordMemberIdColumn();

        if ($memberColumn === null) {
            return null;
        }

        return $query
            ->where($schema->recordSessionIdColumn(), $sessionId)
            ->where($memberColumn, $memberCode)
            ->first();
    }

    private function isDuplicateException(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo;
        if (isset($errorInfo[1]) && (int) $errorInfo[1] === 1062) {
            return true;
        }

        if ((string) $exception->getCode() === '23505') {
            return true;
        }

        $message = $exception->getMessage();

        return strpos($message, 'UNIQUE constraint failed') !== false
            || strpos($message, 'Duplicate entry') !== false;
    }

    private function invalidateDashboardCaches(): void
    {
        CacheHelper::forget('dashboard.summary');
        CacheHelper::forget('dashboard.insights');
    }
}
