<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/scan', function () {
    return response()->file(resource_path('kiosk/PublicAttendance.html'), [
        'Content-Type' => 'text/html; charset=UTF-8',
    ]);
});

Route::get('/scanpage/PublicAttendance.html', function () {
    return response()->file(resource_path('kiosk/PublicAttendance.html'), [
        'Content-Type' => 'text/html; charset=UTF-8',
    ]);
});

Route::get('/scanpage/PublicAttendance.jsx', function () {
    return response()->file(resource_path('kiosk/PublicAttendance.jsx'), [
        'Content-Type' => 'application/javascript; charset=UTF-8',
    ]);
});

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
