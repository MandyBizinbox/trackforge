<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EquipmentCategoryController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\DamageReportController;
use App\Http\Controllers\UserController;

Route::get('/health', function () {
    return response()->json([
        'ok' => true,
        'app' => 'TrackForge QM',
        'status' => 'online',
    ]);
});

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('api.token')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::apiResource('categories', EquipmentCategoryController::class)->middleware('role:admin,qm');
    Route::apiResource('equipment', EquipmentController::class);
    Route::get('/availability', [EquipmentController::class, 'availability']);

    Route::apiResource('bookings', BookingController::class);
    Route::post('/bookings/{booking}/submit', [BookingController::class, 'submit']);
    Route::post('/bookings/{booking}/approve', [BookingController::class, 'approve'])->middleware('role:admin,qm');
    Route::post('/bookings/{booking}/reject', [BookingController::class, 'reject'])->middleware('role:admin,qm');
    Route::put('/bookings/{booking}/logistics', [BookingController::class, 'logistics'])->middleware('role:admin,qm');
    Route::post('/bookings/{booking}/notify-logistics', [BookingController::class, 'notifyLogistics'])->middleware('role:admin,qm');
    Route::post('/bookings/{booking}/issue', [BookingController::class, 'issue'])->middleware('role:admin,qm');
    Route::post('/bookings/{booking}/return', [BookingController::class, 'returnItems'])->middleware('role:admin,qm');

    Route::apiResource('damage-reports', DamageReportController::class);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

    Route::post('/push/subscribe', [PushSubscriptionController::class, 'subscribe']);
    Route::post('/push/unsubscribe', [PushSubscriptionController::class, 'unsubscribe']);
    Route::post('/push/test', [PushSubscriptionController::class, 'test']);

    Route::apiResource('users', UserController::class)->middleware('role:admin,qm');
});
