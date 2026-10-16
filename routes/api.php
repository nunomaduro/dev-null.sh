<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CommitController as ApiCommitController;
use App\Http\Controllers\Api\CommitReplyController;
use App\Http\Controllers\Api\CurrentUserController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationGroupController;
use App\Http\Controllers\Api\UserCommitController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserFollowController;
use App\Http\Controllers\Cli\CommitController;
use App\Http\Controllers\Cli\KeyLookupController;
use App\Http\Middleware\AuthenticateSignedRequest;
use App\Http\Middleware\AuthenticateSshSignature;
use App\Http\Middleware\ThrottleCommits;
use Illuminate\Support\Facades\Route;

Route::prefix('cli')->group(function (): void {
    Route::post('commits', [CommitController::class, 'store'])
        ->middleware([AuthenticateSshSignature::class, ThrottleCommits::class])
        ->name('cli-commit.store');
    Route::post('key-lookups', [KeyLookupController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('cli-key-lookup.store');
});

Route::prefix('v1')->middleware('throttle:api')->group(function (): void {
    // Public, or as the web shows them to the user when signed by the mobile app...
    Route::middleware(AuthenticateSignedRequest::optional())->group(function (): void {
        Route::get('users/{user:username}', [UserController::class, 'show'])
            ->name('api-user.show');
        Route::get('users/{user:username}/commits', [UserCommitController::class, 'index'])
            ->name('api-user-commit.index');
        Route::get('commits/{commit}', [ApiCommitController::class, 'show'])
            ->name('api-commit.show');
    });

    // Signing in: the app redeems the code it got on the web, signed with a key that isn't approved yet...
    Route::post('devices', [DeviceController::class, 'store'])
        ->withoutMiddleware('throttle:api')
        ->middleware('throttle:devices')
        ->name('api-device.store');

    // Signed by the mobile app with an SSH key that the user approved as a device...
    Route::middleware(AuthenticateSignedRequest::class)->group(function (): void {
        Route::get('me', [CurrentUserController::class, 'show'])->name('api-current-user.show');

        // Log...
        Route::get('log/{log}', [LogController::class, 'show'])->name('api-log.show');

        // Commit...
        Route::post('commits', [ApiCommitController::class, 'store'])
            ->middleware(ThrottleCommits::class)
            ->name('api-commit.store');
        Route::delete('commits/{commit}', [ApiCommitController::class, 'destroy'])->name('api-commit.destroy');

        // Commit Reply...
        Route::get('commits/{commit}/replies', [CommitReplyController::class, 'index'])
            ->withTrashed()
            ->name('api-commit-reply.index');

        // User Follow...
        Route::post('users/{user:username}/follow', [UserFollowController::class, 'store'])
            ->middleware('throttle:follows')
            ->name('api-user-follow.store');
        Route::delete('users/{user:username}/follow', [UserFollowController::class, 'destroy'])
            ->middleware('throttle:follows')
            ->name('api-user-follow.destroy');

        // Notifications...
        Route::get('notifications', [NotificationController::class, 'index'])->name('api-notification.index');
        Route::get('notifications/{notification}/group', [NotificationGroupController::class, 'show'])->name('api-notification-group.show');
    });
});
