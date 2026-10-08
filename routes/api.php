<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendanceRegularizationController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\DesignationController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeDocumentController;
use App\Http\Controllers\Api\V1\EmployeeHistoryController;
use App\Http\Controllers\Api\V1\EmployeeProfileController;
use App\Http\Controllers\Api\V1\HierarchyController;
use App\Http\Controllers\Api\V1\HolidayController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\OfficeLocationController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\ChatMessageController;
use App\Http\Controllers\Api\V1\ProjectFileController;
use App\Http\Controllers\Api\V1\PresenceController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TimeEntryController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\NotificationController;
use Illuminate\Support\Facades\Route;

// Ids in URLs are always numeric; anything else is a 404.
Route::pattern('department', '[0-9]+');
Route::pattern('designation', '[0-9]+');
Route::pattern('employee', '[0-9]+');
Route::pattern('project', '[0-9]+');
Route::pattern('member', '[0-9]+');
Route::pattern('task', '[0-9]+');
Route::pattern('entry', '[0-9]+');
Route::pattern('conversation', '[0-9]+');
Route::pattern('message', '[0-9]+');
Route::pattern('file', '[0-9]+');
Route::pattern('upload', '[0-9]+');
Route::pattern('attachment', '[0-9]+');
Route::pattern('user', '[0-9]+');

Route::prefix('v1')->group(function () {

    // ---- Public ----
    Route::get('/ping', function () {
        return response()->json(['success' => true, 'message' => 'pong']);
    });

    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:password-reset');
    Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');

    // ---- Authenticated (Bearer token, active account) ----
    Route::middleware(['auth:sanctum', 'active'])->group(function () {

        Route::prefix('auth')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/change-password', [AuthController::class, 'changePassword']);
        });

        Route::post('/uploads', [UploadController::class, 'store'])->middleware('throttle:30,1');
        Route::get('/uploads/{upload}/download', [UploadController::class, 'download']);

        // ---- Organization ----
        Route::middleware('permission:organization.view')->group(function () {
            Route::get('/company', [CompanyController::class, 'show']);

            Route::get('/departments', [DepartmentController::class, 'index']);
            Route::get('/departments/{department}', [DepartmentController::class, 'show']);

            Route::get('/designations', [DesignationController::class, 'index']);
            Route::get('/designations/{designation}', [DesignationController::class, 'show']);
        });

        Route::middleware('permission:organization.manage')->group(function () {
            Route::put('/company', [CompanyController::class, 'update']);

            Route::post('/departments', [DepartmentController::class, 'store']);
            Route::put('/departments/{department}', [DepartmentController::class, 'update']);
            Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);

            Route::post('/designations', [DesignationController::class, 'store']);
            Route::put('/designations/{designation}', [DesignationController::class, 'update']);
            Route::delete('/designations/{designation}', [DesignationController::class, 'destroy']);
        });

        // ---- Employees & hierarchy ----
        Route::middleware('permission:employees.view')->group(function () {
            Route::get('/roles', [RoleController::class, 'index']);
            Route::get('/employees', [EmployeeController::class, 'index']);
            Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
            Route::get('/hierarchy', [HierarchyController::class, 'index']);
            Route::get('/employees/{employee}/profile', [EmployeeProfileController::class, 'show']);
            Route::get('/employees/{employee}/documents', [EmployeeDocumentController::class, 'index']);
            Route::get('/employees/{employee}/history', [EmployeeHistoryController::class, 'index']);
        });

        Route::middleware('permission:employees.manage')->group(function () {
            Route::post('/employees', [EmployeeController::class, 'store']);
            Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
            Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);
            Route::post('/employees/{employee}/history', [EmployeeHistoryController::class, 'store']);
        });

        // Profile self/manager update
        Route::put('/employees/{employee}/profile', [EmployeeProfileController::class, 'update']);
        Route::post('/employees/{employee}/documents', [EmployeeDocumentController::class, 'store']);
        Route::put('/documents/{document}/verify', [EmployeeDocumentController::class, 'verify']);
        Route::delete('/documents/{document}', [EmployeeDocumentController::class, 'destroy']);

        // ---- Attendance & Locations ----
        Route::prefix('attendance')->group(function () {
            Route::get('/today', [AttendanceController::class, 'today']);
            Route::post('/clock-in', [AttendanceController::class, 'clockIn']);
            Route::post('/clock-out', [AttendanceController::class, 'clockOut']);
            Route::get('/my-records', [AttendanceController::class, 'myRecords']);
            Route::get('/summary', [AttendanceController::class, 'summary']);

            Route::get('/regularizations', [AttendanceRegularizationController::class, 'index']);
            Route::post('/regularizations', [AttendanceRegularizationController::class, 'store']);

            Route::middleware('permission:attendance.view')->group(function () {
                Route::get('/records', [AttendanceController::class, 'records']);
            });

            Route::middleware('permission:attendance.manage')->group(function () {
                Route::put('/regularizations/{id}/approve', [AttendanceRegularizationController::class, 'approve']);
                Route::put('/regularizations/{id}/reject', [AttendanceRegularizationController::class, 'reject']);
            });
        });

        Route::get('/office-locations', [OfficeLocationController::class, 'index']);
        Route::middleware('permission:attendance.manage')->group(function () {
            Route::post('/office-locations', [OfficeLocationController::class, 'store']);
            Route::put('/office-locations/{id}', [OfficeLocationController::class, 'update']);
            Route::delete('/office-locations/{id}', [OfficeLocationController::class, 'destroy']);
        });

        // ---- Leaves ----
        Route::prefix('leaves')->group(function () {
            Route::get('/types', [LeaveController::class, 'types']);
            Route::get('/balances', [LeaveController::class, 'balances']);
            Route::get('/my-requests', [LeaveController::class, 'myRequests']);
            Route::post('/requests', [LeaveController::class, 'storeRequest']);
            Route::put('/requests/{id}/cancel', [LeaveController::class, 'cancelRequest']);

            Route::middleware('permission:leave.view')->group(function () {
                Route::get('/requests', [LeaveController::class, 'requests']);
            });

            Route::middleware('permission:leave.approve')->group(function () {
                Route::put('/requests/{id}/approve', [LeaveController::class, 'approveRequest']);
                Route::put('/requests/{id}/reject', [LeaveController::class, 'rejectRequest']);
            });

            Route::middleware('permission:leave.manage')->group(function () {
                Route::post('/types', [LeaveController::class, 'storeType']);
                Route::put('/types/{id}', [LeaveController::class, 'updateType']);
            });
        });

        // ---- Holidays ----
        Route::get('/holidays', [HolidayController::class, 'index']);
        Route::middleware('permission:holidays.manage')->group(function () {
            Route::post('/holidays', [HolidayController::class, 'store']);
            Route::put('/holidays/{id}', [HolidayController::class, 'update']);
            Route::delete('/holidays/{id}', [HolidayController::class, 'destroy']);
        });

        // ---- Projects (Phase 4) ----
        Route::prefix('projects')->group(function () {
            Route::get('/dashboard', [ProjectController::class, 'dashboard'])->middleware('permission:projects.view');

            Route::middleware('permission:projects.view')->group(function () {
                Route::get('/', [ProjectController::class, 'index']);
                Route::get('/{project}', [ProjectController::class, 'show']);
                Route::get('/{project}/members', [ProjectController::class, 'members']);
                Route::get('/{project}/activities', [ProjectController::class, 'activities']);
            });

            Route::middleware('permission:projects.manage')->group(function () {
                Route::post('/', [ProjectController::class, 'store']);
                Route::put('/{project}', [ProjectController::class, 'update']);
                Route::delete('/{project}', [ProjectController::class, 'destroy']);
            });

            // Status transitions (Admins, Managers, Team Leads on assigned projects)
            Route::post('/{project}/status', [ProjectController::class, 'updateStatus'])->middleware('permission:projects.status');

            // Team Lead assignment (Admins & Managers only)
            Route::post('/{project}/lead', [ProjectController::class, 'assignLead'])->middleware('permission:projects.assign');

            // Team Members management (Admins, Managers, and Team Leads on own projects)
            Route::middleware('permission:projects.team')->group(function () {
                Route::post('/{project}/members', [ProjectController::class, 'addMember']);
                Route::put('/{project}/members/{member}', [ProjectController::class, 'updateMember']);
                Route::delete('/{project}/members/{member}', [ProjectController::class, 'removeMember']);
            });

            // Project tasks (Phase 5)
            Route::get('/{project}/tasks', [TaskController::class, 'index'])->middleware('permission:tasks.view');
            Route::post('/{project}/tasks', [TaskController::class, 'store'])->middleware('permission:tasks.manage');

            // Project completion workflow
            Route::post('/{project}/request-completion', [ProjectController::class, 'requestCompletion']);
            Route::post('/{project}/approve-completion', [ProjectController::class, 'approveCompletion']);
            Route::post('/{project}/reject-completion', [ProjectController::class, 'rejectCompletion']);

            // Project files (Phase 6)
            Route::get('/{project}/files', [ProjectFileController::class, 'index']);
            Route::post('/{project}/files', [ProjectFileController::class, 'store']);
            Route::get('/{project}/files/{file}/download', [ProjectFileController::class, 'download']);
            Route::put('/{project}/files/{file}', [ProjectFileController::class, 'update']);
            Route::post('/{project}/files/{file}/version', [ProjectFileController::class, 'version']);
            Route::get('/{project}/files/{file}/versions', [ProjectFileController::class, 'versions']);
            Route::get('/{project}/files/{file}/versions/{version}/download', [ProjectFileController::class, 'downloadVersion']);
            Route::post('/{project}/files/{file}/versions/{version}/restore', [ProjectFileController::class, 'restoreVersion']);
            Route::delete('/{project}/files/{file}', [ProjectFileController::class, 'destroy']);
        });

        // ---- Tasks (Phase 5) ----
        Route::prefix('tasks')->group(function () {
            Route::get('/my', [TaskController::class, 'myTasks'])->middleware('permission:tasks.view');
            Route::get('/my-work-today', [TaskController::class, 'myWorkToday'])->middleware('permission:tasks.view');
            Route::get('/{task}', [TaskController::class, 'show'])->middleware('permission:tasks.view');
            Route::put('/{task}', [TaskController::class, 'update'])->middleware('permission:tasks.manage');
            Route::delete('/{task}', [TaskController::class, 'destroy'])->middleware('permission:tasks.manage');
            Route::post('/{task}/status', [TaskController::class, 'updateStatus'])->middleware('permission:tasks.update');
            Route::post('/{task}/assign', [TaskController::class, 'assign'])->middleware('permission:tasks.manage');
            Route::get('/{task}/subtasks', [TaskController::class, 'subtasks'])->middleware('permission:tasks.view');
            Route::post('/{task}/subtasks', [TaskController::class, 'createSubtask'])->middleware('permission:tasks.manage');
            Route::get('/{task}/messages', [ChatController::class, 'taskMessages'])->middleware('permission:tasks.view');

            // Timer & manual time entries
            Route::post('/{task}/timer/start', [TimeEntryController::class, 'startTimer'])->middleware('permission:time.track');
            Route::post('/{task}/timer/stop', [TimeEntryController::class, 'stopTimer'])->middleware('permission:time.track');
            Route::post('/{task}/timer/pause', [TimeEntryController::class, 'pauseTimer'])->middleware('permission:time.track');
            Route::post('/{task}/timer/resume', [TimeEntryController::class, 'resumeTimer'])->middleware('permission:time.track');
            Route::post('/{task}/time-entries', [TimeEntryController::class, 'storeManual'])->middleware('permission:time.track');
        });

        // ---- Time Entries & Timesheet (Phase 5) ----
        Route::prefix('time-entries')->group(function () {
            Route::get('/', [TimeEntryController::class, 'index'])->middleware('permission:time.view');
            Route::get('/active', [TimeEntryController::class, 'activeTimer'])->middleware('permission:time.track');
            Route::delete('/{entry}', [TimeEntryController::class, 'destroy'])->middleware('permission:time.track');
        });

        Route::get('/timesheet', [TimeEntryController::class, 'timesheet'])->middleware('permission:time.view');
        Route::get('/timesheet/team', [TimeEntryController::class, 'teamTimesheet'])->middleware('permission:time.view');

        // ---- Internal Chat & Messaging (Phase 6) ----
        Route::prefix('conversations')->middleware('permission:chat.use')->group(function () {
            Route::get('/', [ChatController::class, 'index']);
            Route::get('/unread-summary', [ChatController::class, 'unreadSummary']);
            Route::get('/search', [ChatMessageController::class, 'search']);
            Route::get('/colleagues', [ChatController::class, 'colleagues']);
            Route::post('/direct', [ChatController::class, 'direct']);
            Route::post('/group', [ChatController::class, 'group']);
            Route::get('/project/{project}', [ChatController::class, 'forProject']);
            Route::get('/{conversation}', [ChatController::class, 'show']);
            Route::put('/{conversation}', [ChatController::class, 'update']);
            Route::post('/{conversation}/mute', [ChatController::class, 'mute']);
            Route::post('/{conversation}/leave', [ChatController::class, 'leave']);
            Route::post('/{conversation}/participants', [ChatController::class, 'addParticipants']);
            Route::delete('/{conversation}/participants/{user}', [ChatController::class, 'removeParticipant']);

            // Messages inside conversation
            Route::get('/{conversation}/messages', [ChatMessageController::class, 'index']);
            Route::post('/{conversation}/messages', [ChatMessageController::class, 'store'])->middleware('throttle:60,1');
            Route::put('/{conversation}/messages/{message}', [ChatMessageController::class, 'update']);
            Route::post('/{conversation}/messages/{message}/pin', [ChatMessageController::class, 'pin']);
            Route::post('/{conversation}/read', [ChatMessageController::class, 'markAsRead']);
            Route::post('/{conversation}/typing', [ChatMessageController::class, 'typing'])->middleware('throttle:60,1');
            Route::get('/{conversation}/typing', [ChatMessageController::class, 'getTyping']);
            Route::delete('/{conversation}/messages/{message}', [ChatMessageController::class, 'destroy']);
            Route::get('/{conversation}/attachments/{attachment}/download', [ChatMessageController::class, 'downloadAttachment']);
        });

        // ---- Live Online / Offline Presence Heartbeat (Phase 6) ----
        Route::prefix('presence')->group(function () {
            Route::post('/heartbeat', [PresenceController::class, 'heartbeat'])->middleware('throttle:60,1');
            Route::post('/offline', [PresenceController::class, 'offline'])->middleware('throttle:60,1');
            Route::get('/', [PresenceController::class, 'index']);
            Route::put('/privacy', [PresenceController::class, 'updatePrivacy']);
        });

        // ---- Push-Ready Device Tokens (Phase 8 FCM Preparation) ----
        Route::post('/device-tokens', [DeviceTokenController::class, 'store']);
        Route::delete('/device-tokens', [DeviceTokenController::class, 'destroy']);

        // ---- Notifications Inbox (Phase 6 / 7) ----
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
            Route::post('/read-all', [NotificationController::class, 'markAllRead']);
            Route::post('/{id}/read', [NotificationController::class, 'markRead']);
            Route::delete('/{id}', [NotificationController::class, 'destroy']);
        });

        // ---- Broadcasting Authorization (Pusher / Soketi WebSockets) ----
        Route::post('/broadcasting/auth', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Support\Facades\Broadcast::auth($request);
        });
    });
});