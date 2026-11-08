<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OpportunityController;
use App\Http\Controllers\Api\OrganizationDashboardController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\StudentDashboardController;
use App\Http\Controllers\Employer\ApplicationController as EmployerApplication;
use App\Http\Controllers\Employer\OpportunityController as EmployerOpportunity;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\Student\ApplicationController as StudentApplication;
use App\Models\Course;
use App\Models\University;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user()->only(['id', 'name', 'email', 'role']);
})->middleware(['auth:sanctum', 'active']);

Route::prefix('v1')->group(function () {
    Route::post('/register/student', [AuthController::class, 'registerStudent'])->middleware('throttle:5,1');
    Route::post('/register/organization', [AuthController::class, 'registerOrganization'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:3,1');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1');
    Route::get('/universities', fn () => response()->json([
        'data' => University::query()->orderBy('name')->get(['id', 'name']),
    ]));
    Route::get('/universities/{university}/courses', fn (University $university) => response()->json([
        'data' => Course::query()->where('university_id', $university->id)->orderBy('name')->get(['id', 'university_id', 'name']),
    ]));
    Route::get('/opportunities', [OpportunityController::class, 'index']);
    Route::get('/opportunities/{opportunity}', [OpportunityController::class, 'show']);

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::prefix('student')->middleware('role:student')->group(function () {
            Route::get('/dashboard', [StudentDashboardController::class, 'dashboard']);
            Route::get('/profile', [StudentDashboardController::class, 'profile']);
            Route::put('/profile', [StudentDashboardController::class, 'updateProfile']);
            Route::get('/applications', [StudentApplication::class, 'index']);
            Route::post('/applications', [StudentApplication::class, 'store']);
            Route::get('/applications/{application}', [StudentApplication::class, 'show']);
            Route::get('/applications/{application}/offer-letter', [StudentApplication::class, 'downloadOfferLetter']);
        });

        Route::prefix('employer')->middleware('role:employer,organization')->group(function () {
            Route::get('/dashboard', [OrganizationDashboardController::class, 'dashboard']);
            Route::get('/profile', [OrganizationDashboardController::class, 'profile']);
            Route::put('/profile', [OrganizationDashboardController::class, 'updateProfile']);
            Route::get('/opportunities', [EmployerOpportunity::class, 'index']);
            Route::post('/opportunities', [EmployerOpportunity::class, 'store']);
            Route::get('/opportunities/{opportunity}', [EmployerOpportunity::class, 'show']);
            Route::put('/opportunities/{opportunity}', [EmployerOpportunity::class, 'update']);
            Route::patch('/opportunities/{opportunity}/status', [EmployerOpportunity::class, 'updateStatus']);
            Route::delete('/opportunities/{opportunity}', [EmployerOpportunity::class, 'destroy']);
            Route::get('/applications', [EmployerApplication::class, 'index']);
            Route::get('/applications/{application}', [EmployerApplication::class, 'show']);
            Route::patch('applications/{application}/status', [EmployerApplication::class, 'updateStatus']);
            Route::post('applications/{application}/offer-letter', [EmployerApplication::class, 'uploadOfferLetter']);
        });

        Route::middleware('role:student,employer,organization')->group(function () {
            Route::get('applications/{application}/messages', [MessageController::class, 'index']);
            Route::post('applications/{application}/messages', [MessageController::class, 'store']);
        });
    });
});
