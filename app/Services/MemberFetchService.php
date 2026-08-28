<?php

namespace App\Services;

use App\Support\CacheHelper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fetch members from the external system on demand.
 *
 * The external system stays the source of truth — nothing is written into the
 * local `member` table. Attendance records reference external members by their
 * member_code (member_id stays null for them). Names are resolved here at read
 * time and cached briefly so repeated lookups don't hammer the external URL.
 */
class MemberFetchService
{
    private const LIST_CACHE_KEY = 'integration.external_members';
    private const LAST_GOOD_CACHE_KEY = 'integration.external_members.last_good';
    private const LAST_FETCH_KEY = 'integration.last_fetch';
    private const FETCH_META_KEY = 'integration.fetch_meta';

    private $degraded = false;
    private $lastError;
    private $fetchMeta = [];

    /**
     * All external members, normalized to `member` table column names.
     * Cached 60s; returns [] when the integration is not configured or fails.
     */
    public function list(): array
    {
        $cached = Cache::get(CacheHelper::key(self::LIST_CACHE_KEY));

        if (is_array($cached)) {
            $this->fetchMeta = (array) Cache::get(CacheHelper::key(self::FETCH_META_KEY), []);

            return $cached;
        }

        try {
            return $this->fetchExternal();
        } catch (\Throwable $e) {
            $this->degraded = true;
            $this->lastError = $e->getMessage();
            $lastGood = Cache::get(CacheHelper::key(self::LAST_GOOD_CACHE_KEY));

            if (is_array($lastGood)) {
                Log::warning('integration.fetch is using the last known-good member cache: ' . $e->getMessage());

                return $lastGood;
            }

            throw $e;
        }
    }

    public function refreshList(): array
    {
        return $this->fetchExternal();
    }

    public function findByCode(string $code): ?array
    {
        $code = strtoupper(trim($code));

        foreach ($this->list() as $member) {
            if (strtoupper(trim((string) ($member['member_code'] ?? ''))) === $code) {
                return $member;
            }
        }

        return null;
    }

    /**
     * Resolve one scanned identifier without importing or persisting members.
     */
    public function findByIdentifierLive(string $identifier): ?array
    {
        $listUrl = rtrim((string) config('integration.url', ''), '/');
        $key = (string) config('integration.growth_track_key', '');

        if ($listUrl === '' || $key === '') {
            return null;
        }

        try {
            $response = (new Client(['timeout' => (int) config('integration.timeout', 10)]))->get($listUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-WOH-GT-API-Key' => $key,
                    'Authorization' => 'Bearer ' . $key,
                ],
                'query' => ['search' => $identifier, 'per_page' => 1],
            ]);
        } catch (\Throwable $e) {
            Log::warning('growth_track member lookup failed: ' . $e->getMessage());

            return null;
        }

        $payload = json_decode((string) $response->getBody(), true);
        $data = is_array($payload) ? ($payload['data'] ?? $payload) : null;

        if (is_array($data) && isset($data['member'])) {
            $data = $data['member'];
        }
        if (is_array($data) && isset($data[0])) {
            $data = $data[0];
        }
        if (!is_array($data) || empty($data)) {
            return null;
        }

        $personal = is_array($data['personal_information'] ?? null) ? $data['personal_information'] : [];
        $firstName = $data['first_name'] ?? $personal['first_name'] ?? '';
        $lastName = $data['last_name'] ?? $personal['last_name'] ?? '';

        return [
            'external_member_id' => (string) ($data['member_code'] ?? $data['member_id'] ?? $data['external_id'] ?? $identifier),
            'name' => trim($data['name'] ?? trim($firstName . ' ' . $lastName)),
            'profile_photo_url' => $data['profile_photo_url'] ?? $data['photo'] ?? $data['image_url'] ?? null,
            'member' => $data,
        ];
    }

    /**
     * Fill member name/code onto attendance record rows that reference an
     * external member (i.e. rows with no local member row to join against).
     */
    public function hydrateRecords(Collection $records): void
    {
        $wanted = [];

        foreach ($records as $record) {
            if (!empty($record->member_name_cache) && empty($record->first_name)) {
                $record->first_name = $record->member_name_cache;
                $record->last_name = '';
            }

            if (empty($record->first_name)) {
                if (!empty($record->member_code)) {
                    $wanted['code:' . $record->member_code] = true;
                } elseif (!empty($record->member_id)) {
                    $wanted['id:' . $record->member_id] = true;
                }
            }
        }

        if (empty($wanted)) {
            return;
        }

        $external = [];

        foreach ($this->list() as $member) {
            if (!empty($member['member_code']) && isset($wanted['code:' . $member['member_code']])) {
                $external['code:' . $member['member_code']] = $member;
            }
            if (!empty($member['external_id']) && isset($wanted['id:' . $member['external_id']])) {
                $external['id:' . $member['external_id']] = $member;
            }
        }

        foreach ($records as $record) {
            $key = !empty($record->member_code)
                ? 'code:' . $record->member_code
                : (!empty($record->member_id) ? 'id:' . $record->member_id : null);

            if ($key !== null && empty($record->first_name) && isset($external[$key])) {
                $member = $external[$key];
                $record->member_id = null;
                $record->first_name = $member['first_name'] ?? null;
                $record->middle_name = $member['middle_name'] ?? null;
                $record->last_name = $member['last_name'] ?? null;
                $record->suffix_name = $member['suffix_name'] ?? null;
                $record->member_code = $member['member_code'] ?? $record->member_code;
            }
        }
    }

    public function fetch(bool $refresh = false): array
    {
        $members = $refresh ? $this->refreshList() : $this->list();

        return [
            'count'   => count($members),
            'members' => $members,
            'degraded' => $this->degraded,
            'truncated' => !empty($this->fetchMeta['truncated']),
            'pages_fetched' => (int) ($this->fetchMeta['pages_fetched'] ?? 0),
            'total_pages' => (int) ($this->fetchMeta['total_pages'] ?? 0),
            'error' => $this->lastError,
        ];
    }

    public function status(): array
    {
        return [
            'configured'       => !empty(config('integration.url')),
            'url'              => config('integration.url'),
            'timeout'          => (int) config('integration.timeout', 10),
            'fields_mapped'    => count(array_filter(config('integration.mapping', []))),
            'sample_available' => count((array) $this->extractList(config('integration.sample_payload', []))) > 0,
            'last_fetched_at'  => Cache::get(CacheHelper::key(self::LAST_FETCH_KEY)),
            'fetch_meta'       => Cache::get(CacheHelper::key(self::FETCH_META_KEY), []),
        ];
    }

    private function fetchExternal(): array
    {
        $url = config('integration.url');

        if (empty($url)) {
            $this->fetchMeta = ['truncated' => false, 'pages_fetched' => 0, 'total_pages' => 0];

            return [];
        }

        $mapping = array_filter(config('integration.mapping', []));
        $normalized = [];
        $maxPages = 1;
        $totalPages = 1;
        $truncated = false;
        $probedPagination = false;
        $page = 1;

        while ($page <= $maxPages) {
            $payload = $this->requestPage($url, $page);

            // The first page reveals how many pages to follow (once).
            if (!$probedPagination) {
                $probedPagination = true;
                $totalPages = $this->totalPagesFromPayload($payload);
                $pageCap = max(1, (int) config('integration.pagination.max_pages', 20));
                $maxPages = min($totalPages, $pageCap);
                $truncated = $totalPages > $pageCap;
            }

            $list = $this->extractList($payload);

            if (!is_array($list)) {
                throw new \RuntimeException('The member list was missing on integration page ' . $page . '.');
            }

            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $row = $this->mapItem($item, $mapping);
                $canonicalCode = strtoupper(trim((string) ($row['member_code'] ?? '')));

                if ($canonicalCode !== '') {
                    $row['member_code'] = $canonicalCode;
                    $normalized[$canonicalCode] = $row;
                }
            }

            if ($page >= $maxPages) {
                break;
            }

            if (empty($list)) {
                throw new \RuntimeException('Integration pagination ended unexpectedly on page ' . $page . '.');
            }

            $page++;
        }

        $normalized = array_values($normalized);
        $this->degraded = false;
        $this->lastError = null;
        $this->fetchMeta = [
            'truncated' => $truncated,
            'pages_fetched' => $page,
            'total_pages' => $totalPages,
            'max_pages' => $maxPages,
        ];

        Cache::put(CacheHelper::key(self::LIST_CACHE_KEY), $normalized, 60);
        Cache::forever(CacheHelper::key(self::FETCH_META_KEY), $this->fetchMeta);

        if (!$truncated) {
            Cache::forever(CacheHelper::key(self::LAST_GOOD_CACHE_KEY), $normalized);
        }

        Cache::put(CacheHelper::key(self::LAST_FETCH_KEY), now()->toDateTimeString());

        return $normalized;
    }

    private function requestPage(string $url, int $page): array
    {
        $headers = ['Accept' => 'application/json'];
        $key = config('integration.key');

        if (!empty($key)) {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        $options = ['headers' => $headers];

        if ($page > 1) {
            $options['query'] = [
                config('integration.pagination.query_param', 'page') => $page,
            ];
        }

        try {
            $client = new Client(['timeout' => (int) config('integration.timeout', 10)]);
            $response = $client->get($url, $options);
        } catch (\Throwable $e) {
            Log::warning('integration.fetch page ' . $page . ' failed: ' . $e->getMessage());

            throw new \RuntimeException('Integration page ' . $page . ' could not be fetched.', 0, $e);
        }

        $payload = json_decode((string) $response->getBody(), true);

        if (!is_array($payload)) {
            Log::warning('integration.fetch returned invalid JSON on page ' . $page . '.');

            throw new \RuntimeException('Integration page ' . $page . ' returned invalid JSON.');
        }

        return $payload;
    }

    private function totalPagesFromPayload(array $payload): int
    {
        $enabled = (bool) config('integration.pagination.enabled', false);

        if (!$enabled) {
            return 1;
        }

        $totalPages = $this->getByPath($payload, config('integration.pagination.total_pages_path', 'pagination.total_pages'));

        if (!is_numeric($totalPages)) {
            return 1;
        }

        return max(1, (int) $totalPages);
    }

    private function extractList(array $payload)
    {
        return $this->getByPath($payload, config('integration.members_path', 'members'));
    }

    private function getByPath(array $array, string $path)
    {
        $value = $array;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private function mapItem(array $item, array $mapping): array
    {
        $allowed = [
            'external_id', 'member_code', 'first_name', 'middle_name', 'last_name', 'suffix_name',
            'gender', 'birth_date', 'mobile_number', 'email', 'address_line',
            'membership_status', 'date_joined', 'notes', 'ministries',
        ];

        $row = [];

        foreach ($mapping as $column => $externalKey) {
            if (!in_array($column, $allowed, true) || !array_key_exists($externalKey, $item)) {
                continue;
            }

            if ($column === 'ministries') {
                $row['ministries'] = $this->normalizeMinistries($item[$externalKey]);

                continue;
            }

            $value = is_scalar($item[$externalKey]) ? trim((string) $item[$externalKey]) : null;
            $value = $value === '' ? null : $value;

            if (in_array($column, ['birth_date', 'date_joined'], true) && $value !== null) {
                if (!$this->isValidDate($value)) {
                    continue;
                }

                $value = Carbon::parse($value)->format('Y-m-d');
            }

            $row[$column] = $value;
        }

        if (empty($row['birth_date'])) {
            foreach (['birth_date', 'date_of_birth', 'birthday'] as $birthDateKey) {
                if (!empty($item[$birthDateKey]) && is_scalar($item[$birthDateKey])
                    && $this->isValidDate((string) $item[$birthDateKey])) {
                    $row['birth_date'] = Carbon::parse((string) $item[$birthDateKey])->format('Y-m-d');
                    break;
                }
            }
        }

        return $row;
    }

    /**
     * The external API sometimes returns ministries as a plain string and
     * sometimes as an array of strings — normalize both to a sorted array.
     */
    private function normalizeMinistries($value): array
    {
        $items = is_array($value) ? $value : [$value];
        $result = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $item = trim($item);

                if ($item !== '' && !in_array($item, $result, true)) {
                    $result[] = $item;
                }
            }
        }

        sort($result);

        return $result;
    }

    /**
     * Distinct ministry names across all external members (sorted).
     */
    public function ministries(): array
    {
        $names = [];

        foreach ($this->list() as $member) {
            foreach ($member['ministries'] ?? [] as $name) {
                $name = trim((string) $name);

                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }

        $list = array_keys($names);
        sort($list);

        return $list;
    }

    /**
     * Member codes of external members that belong to the given ministry.
     */
    public function codesInMinistry(string $ministry): array
    {
        $codes = [];

        foreach ($this->list() as $member) {
            if (in_array($ministry, $member['ministries'] ?? [], true)) {
                $codes[] = $member['member_code'];
            }
        }

        return $codes;
    }

    private function isValidDate(string $value): bool
    {
        try {
            Carbon::parse($value);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
