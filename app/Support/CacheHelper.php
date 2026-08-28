<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class CacheHelper
{
    public static function key(string $key): string
    {
        return 'cas.' . $key;
    }

    public static function remember(string $key, int $ttlSeconds, callable $callback)
    {
        return Cache::remember(self::key($key), $ttlSeconds, $callback);
    }

    public static function rememberForever(string $key, callable $callback)
    {
        return Cache::rememberForever(self::key($key), $callback);
    }

    public static function forget(string $key): void
    {
        Cache::forget(self::key($key));
    }
}
