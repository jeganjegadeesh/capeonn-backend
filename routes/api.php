<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\DesignationController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\HierarchyController;
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
        });

        Route::middleware('permission:employees.manage')->group(function () {
            Route::post('/employees', [EmployeeController::class, 'store']);
            Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
            Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);
        });
    });
});