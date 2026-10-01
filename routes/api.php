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
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\UploadController;
use Illuminate\Support\Facades\Route;

// Ids in URLs are always numeric; anything else is a 404.
Route::pattern('department', '[0-9]+');
Route::pattern('designation', '[0-9]+');
Route::pattern('employee', '[0-9]+');

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
    });
});