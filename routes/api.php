<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AttendanceSessionController;
use App\Http\Controllers\AttendanceRecordController;
use App\Http\Controllers\AttendanceScheduleController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SidebarController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\SystemFeedbackController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return ['message' => 'Hello World'];
});

Route::post('login', 'AuthController@login');
Route::post('v1/attendance/check-in', 'CheckInController@checkIn');

// Kiosk endpoints (read-only public; sync requires Bearer kiosk key)
Route::get('system/status', 'KioskController@status');
Route::get('system/settings/public-attendance', 'KioskController@settings');
Route::get('system/settings/announcement-video', 'KioskController@announcementVideo');
Route::get('attendance/sessions', 'KioskController@sessions');
Route::get('attendance/recent', 'KioskController@recent');
Route::get('attendance/today/by-session', 'KioskController@todayBySession');
Route::get('attendance/birthdays', 'KioskController@birthdays');
Route::post('attendance/sync', 'KioskController@sync');

Route::group(['middleware' => 'auth:sanctum'], function () {
    Route::post('logout', 'AuthController@logout');

    Route::get('dashboard/summary', 'DashboardController@summary');
    Route::get('dashboard/reminders', 'DashboardController@reminders');
    Route::get('dashboard/insights', 'DashboardController@insights');
    Route::get('system/info', 'SystemInfoController@index');

    Route::get('sidebar', 'SidebarController@index');
    Route::post('sidebar', 'SidebarController@update');
    Route::delete('sidebar', 'SidebarController@destroy');
    Route::get('sidebar/routes', 'SidebarController@routes');

    Route::get('audit-logs', 'AuditLogController@index');
    Route::get('audit-logs/modules', 'AuditLogController@modules');
    Route::get('audit-logs/export', 'AuditLogController@export');

     Route::get('attendance-sessions', 'AttendanceSessionController@index');
     Route::patch('attendance-sessions/{id}/status', 'AttendanceSessionController@status');
     Route::get('attendance-sessions/{id}/attendance', 'AttendanceRecordController@showSessionAttendance');
    Route::get('attendance-records', 'AttendanceRecordController@history');
     Route::get('attendance-records/followups', 'AttendanceRecordController@followups');
     Route::post('attendance-records/followups', 'AttendanceRecordController@saveFollowup');
     Route::post('attendance-records', 'AttendanceRecordController@store');
     Route::get('attendance-records/member/{identifier}/stats', 'AttendanceRecordController@memberStats');
     Route::get('attendance-records/export', 'AttendanceRecordController@export');
     Route::delete('attendance-records/{id}', 'AttendanceRecordController@destroy');

    Route::get('report/pdf', 'ReportController@pdf');
    Route::get('report/summary', 'ReportController@summary');

    Route::get('integration/status', 'IntegrationController@status');
    Route::get('integration/fetch', 'IntegrationController@fetch');

    Route::get('attendance-schedules', 'AttendanceScheduleController@index');
    Route::post('attendance-schedules', 'AttendanceScheduleController@create');
    Route::post('attendance-schedules/generate', 'AttendanceScheduleController@generate');
    Route::patch('attendance-schedules/{id}', 'AttendanceScheduleController@update');
    Route::patch('attendance-schedules/{id}/toggle', 'AttendanceScheduleController@toggle');
    Route::delete('attendance-schedules/{id}', 'AttendanceScheduleController@destroy');

    Route::get('services', 'AttendanceScheduleController@index');
    Route::post('services', 'AttendanceScheduleController@create');
    Route::post('services/generate', 'AttendanceScheduleController@generate');
    Route::patch('services/{id}', 'AttendanceScheduleController@update');
    Route::patch('services/{id}/toggle', 'AttendanceScheduleController@toggle');
    Route::delete('services/{id}', 'AttendanceScheduleController@destroy');

    Route::get('system-feedback', 'SystemFeedbackController@index');
    Route::post('system-feedback', 'SystemFeedbackController@store');
    Route::get('system-feedback/categories', 'SystemFeedbackController@categories');

    Route::get('settings/announcement', 'KioskController@getAnnouncement');
    Route::post('settings/announcement', 'KioskController@updateAnnouncement');
    Route::post('settings/kiosk/provision', 'KioskController@provision');
});
