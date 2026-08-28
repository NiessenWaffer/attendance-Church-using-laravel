<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\CacheHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class SidebarController extends Controller
{
    public function index()
    {
        $items = CacheHelper::remember('sidebar.menu', 3600, function () {
            return $this->read();
        });

        return ApiResponse::success($items);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'items'           => ['required', 'array'],
            'items.*.path'    => ['required', 'string', 'max:120'],
            'items.*.label'   => ['required', 'string', 'max:60'],
            'items.*.icon'    => ['nullable', 'string', 'max:60'],
            'items.*.exact'   => ['boolean'],
            'items.*.enabled' => ['boolean'],
        ]);

        $items = collect($validated['items'])->map(function ($item) {
            return [
                'path'    => $item['path'],
                'label'   => $item['label'],
                'icon'    => $item['icon'] ?? 'menu',
                'exact'   => !empty($item['exact']),
                'enabled' => isset($item['enabled']) ? (bool) $item['enabled'] : true,
            ];
        })->values()->toArray();

        $this->write($items);

        CacheHelper::forget('sidebar.menu');
        AuditLogger::log('settings.sidebar_update', 'settings', 'Updated sidebar menu', ['items' => count($items)]);

        return ApiResponse::success($items, 'Sidebar updated successfully.');
    }

    public function destroy()
    {
        if (File::exists($this->configPath())) {
            File::delete($this->configPath());
        }

        CacheHelper::forget('sidebar.menu');

        return ApiResponse::success($this->defaults(), 'Sidebar reset to defaults.');
    }

    public function routes()
    {
        return ApiResponse::success([
            ['path' => '/', 'label' => 'Dashboard'],
            ['path' => '/members', 'label' => 'Members'],
            ['path' => '/schedules', 'label' => 'Schedules'],
            ['path' => '/attendance', 'label' => 'Attendance'],
            ['path' => '/history', 'label' => 'History'],
            ['path' => '/report', 'label' => 'Report'],
            ['path' => '/audit-logs', 'label' => 'Audit Logs'],
            ['path' => '/settings', 'label' => 'Settings'],
        ]);
    }

    protected function configPath(): string
    {
        return storage_path('app/sidebar-menu.json');
    }

    protected function defaults(): array
    {
        return [
            ['path' => '/', 'label' => 'Dashboard', 'icon' => 'view-dashboard-outline', 'exact' => true, 'enabled' => true],
            ['path' => '/members', 'label' => 'Members', 'icon' => 'account-group-outline', 'exact' => false, 'enabled' => true],
            ['path' => '/schedules', 'label' => 'Schedules', 'icon' => 'calendar-clock-outline', 'exact' => false, 'enabled' => true],
            ['path' => '/attendance', 'label' => 'Attendance', 'icon' => 'calendar-check-outline', 'exact' => false, 'enabled' => true],
            ['path' => '/history', 'label' => 'History', 'icon' => 'history', 'exact' => false, 'enabled' => true],
            ['path' => '/report', 'label' => 'Report', 'icon' => 'file-chart-outline', 'exact' => false, 'enabled' => true],
            ['path' => '/audit-logs', 'label' => 'Audit Logs', 'icon' => 'file-clock-outline', 'exact' => false, 'enabled' => true],
            ['path' => '/settings', 'label' => 'Settings', 'icon' => 'cog-outline', 'exact' => false, 'enabled' => true],
        ];
    }

    protected function read(): array
    {
        $path = $this->configPath();

        if (!File::exists($path)) {
            return $this->defaults();
        }

        $items = json_decode(File::get($path), true);

        return is_array($items) && count($items) ? $items : $this->defaults();
    }

    protected function write(array $items): void
    {
        File::put($this->configPath(), json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
