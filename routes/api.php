<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CommitController as ApiCommitController;
use App\Http\Controllers\Api\CommitProofController;
use App\Http\Controllers\Api\UserCommitController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Cli\CommitController;
use App\Http\Controllers\Cli\KeyLookupController;
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
    Route::get('users/{user:username}', [UserController::class, 'show'])
        ->name('api-user.show');
    Route::get('users/{user:username}/commits', [UserCommitController::class, 'index'])
        ->name('api-user-commit.index');
    Route::get('commits/{commit}/proof', [CommitProofController::class, 'show'])
        ->name('api-commit-proof.show');
    Route::get('commits/{commit}', [ApiCommitController::class, 'show'])
        ->name('api-commit.show');
});
