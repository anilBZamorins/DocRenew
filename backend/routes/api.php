<?php

use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EmployeeProfileController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReminderScheduleConfigController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TenantSettingsController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication & RBAC (FSD 3) — T19
|--------------------------------------------------------------------------
| Public endpoints: login, activate, forgot/reset password.
| Authenticated endpoints: me, refresh, logout — guarded by Sanctum, with
| 'active' re-checking account status on every request (not just login)
| per FSD 3.4's Suspended-account lifecycle rule.
|
| The 'role' middleware alias registered in bootstrap/app.php enforces
| FSD 3.2's permission matrix where a whole route is role-restricted,
| e.g. ->middleware('role:ADMIN,HR'); per-record checks for employee
| profiles and documents are handled by their Policies/FormRequests
| instead, since visibility there depends on the record, not just role.
*/

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/activate', [PasswordController::class, 'activate']);
    Route::post('/forgot-password', [PasswordController::class, 'forgotPassword']);
    Route::post('/reset-password', [PasswordController::class, 'resetPassword']);

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
    });
});

/*
|--------------------------------------------------------------------------
| Employee & Document CRUD (FSD 3.2 / 4.1 / 4.4) — T20
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/employee-profiles', [EmployeeProfileController::class, 'index']);
    Route::post('/employee-profiles', [EmployeeProfileController::class, 'store']);
    Route::get('/employee-profiles/{employee_profile}', [EmployeeProfileController::class, 'show']);
    Route::put('/employee-profiles/{employee_profile}', [EmployeeProfileController::class, 'update']);
    Route::patch('/employee-profiles/{employee_profile}', [EmployeeProfileController::class, 'update']);
    Route::post('/employee-profiles/{employee_profile}/deactivate', [EmployeeProfileController::class, 'deactivate']);
    Route::post('/employee-profiles/{employee_profile}/reactivate', [EmployeeProfileController::class, 'reactivate']);

    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
    Route::put('/documents/{document}', [DocumentController::class, 'update']);
    Route::patch('/documents/{document}', [DocumentController::class, 'update']);
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);
    Route::post('/documents/{document}/renew', [DocumentController::class, 'renew']);
    Route::get('/documents/{document}/reveal-number', [DocumentController::class, 'revealNumber']);

    // T23 — escalation resolution (Manager/HR only; DocumentPolicy::dismissEscalation).
    Route::post('/documents/{document}/escalation/dismiss', [DocumentController::class, 'dismissEscalation']);

    // T31 — dashboard bulk actions (Admin/HR only; DocumentPolicy::reassignOwner/sendManualReminder).
    Route::post('/documents/{document}/reassign', [DocumentController::class, 'reassignOwner']);
    Route::post('/documents/{document}/send-reminder', [DocumentController::class, 'sendManualReminder']);

    // T21 — attachment upload and signed-URL issuance require a live,
    // authorized session (same as every other document action above).
    Route::post('/documents/{document}/attachment', [AttachmentController::class, 'store']);
    Route::get('/documents/{document}/attachment', [AttachmentController::class, 'show']);

    // T22 — reminder scheduling engine.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/scan', [NotificationController::class, 'scan']);

    // T25 — compliance & audit reporting APIs (FSD 4.3 / 3.2). Download
    // is a separate signed-URL step, same split as the attachment
    // endpoints above (ReportController::downloadUrl mints it, stream()
    // below serves it).
    Route::post('/reports', [ReportController::class, 'store']);
    Route::get('/reports/{report}', [ReportController::class, 'show']);
    Route::get('/reports/{report}/download', [ReportController::class, 'downloadUrl']);

    Route::get('/audit-logs', [AuditLogController::class, 'index']);

    // T26 — Admin configuration APIs (FSD 4.5 "Configuration Management").
    // Document types and notification templates are deliberately NOT
    // here — the Jira task scopes this to "reminder rules, org
    // settings" specifically, and notification template copy is T30's
    // job.
    Route::get('/config/reminder-schedules', [ReminderScheduleConfigController::class, 'index']);
    Route::patch('/config/reminder-schedules', [ReminderScheduleConfigController::class, 'update']);

    Route::get('/config/settings', [TenantSettingsController::class, 'show']);
    Route::patch('/config/settings', [TenantSettingsController::class, 'update']);

    // T31 — live compliance dashboard summary tiles (FSD 4.3).
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
});

/*
| T21 — the actual download. Deliberately OUTSIDE auth:sanctum/'active':
| its only credential is a valid, unexpired signature (see
| AttachmentController::show(), which mints it), per FSD 10 "access is
| via short-lived signed URLs," not a bearer token. 'signed' is one of
| Laravel's built-in middleware aliases — no extra registration needed.
*/
Route::get('/attachments/{document}/stream', [AttachmentController::class, 'stream'])
    ->middleware('signed')
    ->name('attachments.stream');

/*
| T25 — the actual report download, same "signature is the only
| credential" posture as attachments.stream above.
*/
Route::get('/reports/{report}/stream', [ReportController::class, 'stream'])
    ->middleware('signed')
    ->name('reports.stream');
