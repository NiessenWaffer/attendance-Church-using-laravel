<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait KioskAuthenticatable
{
    private function authorized(Request $request): bool
    {
        $expected = (string) config('attendance.kiosk_key', '');
        $authorization = (string) $request->header('Authorization', '');
        $provided = preg_replace('/^Bearer\s+/i', '', $authorization);

        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }
}
