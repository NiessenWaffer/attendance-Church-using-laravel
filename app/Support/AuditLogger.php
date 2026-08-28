<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class AuditLogger
{
    /**
     * Write a single audit entry. Never throws — logging failures must not
     * break the primary action being recorded.
     */
    public static function log(
        string $action,
        string $module,
        ?string $description = null,
        array $metadata = [],
        ?string $username = null,
        $userId = null
    ): void {
        try {
            $request = request();
            $user = $request->user();

            if ($user) {
                $userId = $user->getAuthIdentifier();
                $username = $user->username ?? $user->email ?? $user->name ?? null;
            }

            DB::table('audit_logs')->insert([
                'user_id'     => $userId !== null ? (int) $userId : null,
                'username'    => $username,
                'action'      => $action,
                'module'      => $module,
                'description' => $description,
                'ip_address'  => $request->ip(),
                'user_agent'  => mb_substr((string) $request->header('User-Agent'), 0, 255),
                'metadata'    => !empty($metadata) ? json_encode($metadata) : null,
                'created_at'  => now(),
            ]);

            CacheHelper::forget('audit.modules');
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
