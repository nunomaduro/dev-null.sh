<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\CommitController as ApiCommitController;
use App\Http\Controllers\Api\CommitPastelController;
use App\Http\Controllers\Api\CommitPollVoteController;
use App\Http\Controllers\Api\CommitPreviewController;
use App\Http\Controllers\Api\CommitReplyController;
use App\Http\Controllers\Api\CommitReportController;
use App\Http\Controllers\Api\CommitStarController;
use App\Http\Controllers\Api\ConversationArchiveController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\ConversationDropController;
use App\Http\Controllers\Api\ConversationMergeController;
use App\Http\Controllers\Api\ConversationMessageController;
use App\Http\Controllers\Api\ConversationMuteController;
use App\Http\Controllers\Api\ConversationPinController;
use App\Http\Controllers\Api\ConversationReadController;
use App\Http\Controllers\Api\ConversationTypingController;
use App\Http\Controllers\Api\CurrentUserController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\MessageImageController;
use App\Http\Controllers\Api\MessageLinkPreviewController;
use App\Http\Controllers\Api\MessageReactionController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationGroupController;
use App\Http\Controllers\Api\OrgArchiveController;
use App\Http\Controllers\Api\OrgController;
use App\Http\Controllers\Api\OrgMessageController;
use App\Http\Controllers\Api\OrgMuteController;
use App\Http\Controllers\Api\OrgPinController;
use App\Http\Controllers\Api\OrgReadController;
use App\Http\Controllers\Api\OrgTypingController;
use App\Http\Controllers\Api\RemoteCandidateController;
use App\Http\Controllers\Api\ScheduledCommitController;
use App\Http\Controllers\Api\StarredCommitController;
use App\Http\Controllers\Api\UserBlockController;
use App\Http\Controllers\Api\UserCommitController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserFollowController;
use App\Http\Controllers\Api\UserFollowersController;
use App\Http\Controllers\Api\UserFollowingController;
use App\Http\Controllers\Api\UserLaneController;
use App\Http\Controllers\Api\UserMuteController;
use App\Http\Controllers\Api\UserPasteisModeController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\UserRemotePreferencesController;
use App\Http\Controllers\Api\UserRemotesController;
use App\Http\Controllers\Cli\CommitController;
use App\Http\Controllers\Cli\KeyLookupController;
use App\Http\Middleware\AuthenticateSignedRequest;
use App\Http\Middleware\AuthenticateSshSignature;
use App\Http\Middleware\PreventPostingInTimeout;
use App\Http\Middleware\RequireCommitRules;
use App\Http\Middleware\ThrottleCommits;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::prefix('cli')->group(function (): void {
    Route::post('commits', [CommitController::class, 'store'])
        ->middleware([AuthenticateSshSignature::class, PreventPostingInTimeout::class, RequireCommitRules::class, ThrottleCommits::class])
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
        Route::get('users/{user:username}/followers', [UserFollowersController::class, 'index'])
            ->name('api-user-followers.index');
        Route::get('users/{user:username}/following', [UserFollowingController::class, 'index'])
            ->name('api-user-following.index');
        Route::get('commits/{commit}', [ApiCommitController::class, 'show'])
            ->name('api-commit.show');
    });

    // Signing in: the app redeems the code it got on the web, signed with a key that isn't approved yet...
    Route::post('devices', [DeviceController::class, 'store'])
        ->withoutMiddleware('throttle:api')
        ->middleware('throttle:devices')
        ->name('api-device.store');

    // Remote images: the app's image views can't sign requests, so a thread hands out URLs signed for the user...
    Route::get('remotes/images/{messageImage}', [MessageImageController::class, 'show'])
        ->whereUuid('messageImage')
        ->withoutMiddleware('throttle:api')
        ->middleware('signed:relative')
        ->name('api-message-image.show');

    // Signed by the mobile app with an SSH key that the user approved as a device...
    Route::middleware(AuthenticateSignedRequest::class)->group(function (): void {
        Route::get('me', [CurrentUserController::class, 'show'])->name('api-current-user.show');
        Route::delete('me', [CurrentUserController::class, 'destroy'])->name('api-current-user.destroy');

        // Pastéis...
        Route::put('me/pasteis-mode', [UserPasteisModeController::class, 'update'])->name('api-user-pasteis-mode.update');

        // User Profile: mirrored from GitHub again, as Sync from GitHub does on the web...
        Route::put('me/github', [UserProfileController::class, 'update'])
            ->middleware('throttle:profile-syncs')
            ->name('api-user-profile.update');

        // User Lane...
        Route::put('me/lane', [UserLaneController::class, 'update'])->name('api-user-lane.update');

        // User Remotes: the switch from the web's settings, and the settings menu on its Remotes page...
        Route::put('me/remotes', [UserRemotesController::class, 'update'])->name('api-user-remotes.update');
        Route::put('me/remote-preferences', [UserRemotePreferencesController::class, 'update'])
            ->middleware('throttle:conversations')
            ->name('api-user-remote-preferences.update');

        // Log...
        Route::get('log/{log}', [LogController::class, 'show'])->name('api-log.show');

        // Commit...
        Route::post('commits', [ApiCommitController::class, 'store'])
            ->middleware([PreventPostingInTimeout::class, RequireCommitRules::class, ThrottleCommits::class])
            ->name('api-commit.store');
        Route::delete('commits/{commit}', [ApiCommitController::class, 'destroy'])->name('api-commit.destroy');

        // Scheduled Commit...
        Route::get('scheduled-commits', [ScheduledCommitController::class, 'index'])->name('api-scheduled-commit.index');
        Route::put('scheduled-commits/{scheduledCommit}', [ScheduledCommitController::class, 'update'])
            ->middleware(PreventPostingInTimeout::class)
            ->name('api-scheduled-commit.update');
        Route::delete('scheduled-commits/{scheduledCommit}', [ScheduledCommitController::class, 'destroy'])->name('api-scheduled-commit.destroy');

        // Commit Preview: a draft as the commit it would become, which keeps nothing...
        Route::post('commits/preview', [CommitPreviewController::class, 'store'])->name('api-commit-preview.store');

        // Commit Reply...
        Route::get('commits/{commit}/replies', [CommitReplyController::class, 'index'])
            ->withTrashed()
            ->name('api-commit-reply.index');

        // Commit Report: a commit that doesn't exist or was deleted gets the same answer as one the user may not see...
        Route::post('commits/{commit}/reports', [CommitReportController::class, 'store'])
            ->middleware('throttle:commit-reports')
            ->missing(fn (): JsonResponse => ApiException::commitUnavailable()->render())
            ->name('api-commit-report.store');

        // Commit Pastel...
        Route::post('commits/{commit}/pasteis', [CommitPastelController::class, 'store'])
            ->middleware('throttle:60,1')
            ->name('api-commit-pastel.store');

        // Commit Poll Vote...
        Route::post('commits/{commit}/poll/votes', [CommitPollVoteController::class, 'store'])->name('api-commit-poll-vote.store');

        // Private starred commits...
        Route::get('starred', [StarredCommitController::class, 'index'])->name('api-starred.index');

        // Commit Star...
        Route::post('commits/{commit}/star', [CommitStarController::class, 'store'])
            ->middleware('throttle:stars')
            ->name('api-commit-star.store');
        Route::delete('commits/{commit}/star', [CommitStarController::class, 'destroy'])
            ->middleware('throttle:stars')
            ->name('api-commit-star.destroy');

        // User Follow...
        Route::post('users/{user:username}/follow', [UserFollowController::class, 'store'])
            ->middleware('throttle:follows')
            ->name('api-user-follow.store');
        Route::delete('users/{user:username}/follow', [UserFollowController::class, 'destroy'])
            ->middleware('throttle:follows')
            ->name('api-user-follow.destroy');

        // User Mute...
        Route::get('me/muted', [UserMuteController::class, 'index'])->name('api-user-mute.index');
        Route::post('users/{user:username}/mute', [UserMuteController::class, 'store'])->name('api-user-mute.store');
        Route::delete('users/{user:username}/mute', [UserMuteController::class, 'destroy'])->name('api-user-mute.destroy');

        // User Block...
        Route::get('me/blocked', [UserBlockController::class, 'index'])->name('api-user-block.index');
        Route::post('users/{user:username}/block', [UserBlockController::class, 'store'])->name('api-user-block.store');
        Route::delete('users/{user:username}/block', [UserBlockController::class, 'destroy'])->name('api-user-block.destroy');

        // Remotes: stashing or muting a conversation or an org, and merging or dropping a fetched request...
        Route::middleware('throttle:conversations')->group(function (): void {
            Route::post('remotes/@{partner:username}/archive', [ConversationArchiveController::class, 'store'])
                ->name('api-conversation-archive.store');
            Route::delete('remotes/@{partner:username}/archive', [ConversationArchiveController::class, 'destroy'])
                ->name('api-conversation-archive.destroy');
            Route::post('remotes/orgs/{org}/archive', [OrgArchiveController::class, 'store'])
                ->whereUuid('org')
                ->name('api-org-archive.store');
            Route::delete('remotes/orgs/{org}/archive', [OrgArchiveController::class, 'destroy'])
                ->whereUuid('org')
                ->name('api-org-archive.destroy');
            Route::put('remotes/@{partner:username}/mute', [ConversationMuteController::class, 'update'])
                ->name('api-conversation-mute.update');
            Route::put('remotes/@{partner:username}/pin', [ConversationPinController::class, 'update'])
                ->name('api-conversation-pin.update');
            Route::put('remotes/orgs/{org}/mute', [OrgMuteController::class, 'update'])
                ->whereUuid('org')
                ->name('api-org-mute.update');
            Route::put('remotes/orgs/{org}/pin', [OrgPinController::class, 'update'])
                ->whereUuid('org')
                ->name('api-org-pin.update');
            Route::post('remotes/@{partner:username}/merge', [ConversationMergeController::class, 'store'])
                ->name('api-conversation-merge.store');
            Route::post('remotes/@{partner:username}/drop', [ConversationDropController::class, 'store'])
                ->name('api-conversation-drop.store');
        });

        // Remotes: the inbox and threads of the web's Remotes page, polled as often as it polls them...
        Route::middleware('throttle:conversations')->group(function (): void {
            Route::get('remotes', [ConversationController::class, 'index'])->name('api-conversation.index');
            Route::get('remotes/@{partner:username}/messages', [ConversationMessageController::class, 'index'])
                ->name('api-conversation-message.index');
            Route::put('remotes/@{partner:username}/read', [ConversationReadController::class, 'update'])
                ->name('api-conversation-read.update');
            Route::get('remotes/orgs/{org}/messages', [OrgMessageController::class, 'index'])
                ->whereUuid('org')
                ->name('api-org-message.index');
            Route::put('remotes/orgs/{org}/read', [OrgReadController::class, 'update'])
                ->whereUuid('org')
                ->name('api-org-read.update');
            Route::get('remotes/messages/{message}/link-preview', [MessageLinkPreviewController::class, 'show'])
                ->whereNumber('message')
                ->name('api-message-link-preview.show');
        });

        // Remotes: saying the user is typing, as the web's composer does every few seconds...
        Route::middleware('throttle:conversations')->group(function (): void {
            Route::put('remotes/@{partner:username}/typing', [ConversationTypingController::class, 'update'])
                ->name('api-conversation-typing.update');
            Route::put('remotes/orgs/{org}/typing', [OrgTypingController::class, 'update'])
                ->whereUuid('org')
                ->name('api-org-typing.update');
        });

        // Remotes: pushing a message, as often as the web's composer may...
        Route::post('remotes/@{partner:username}/messages', [ConversationMessageController::class, 'store'])
            ->middleware([PreventPostingInTimeout::class, 'throttle:messages'])
            ->name('api-conversation-message.store');
        Route::post('remotes/orgs/{org}/messages', [OrgMessageController::class, 'store'])
            ->whereUuid('org')
            ->middleware([PreventPostingInTimeout::class, 'throttle:messages'])
            ->name('api-org-message.store');

        // Remote Reactions...
        Route::middleware('throttle:conversations')->group(function (): void {
            Route::get('remotes/reactions', [MessageReactionController::class, 'index'])->name('api-message-reaction.index');
            Route::post('remotes/messages/{message}/reactions', [MessageReactionController::class, 'store'])
                ->whereNumber('message')
                ->name('api-message-reaction.store');
            Route::delete('remotes/messages/{message}/reactions', [MessageReactionController::class, 'destroy'])
                ->whereNumber('message')
                ->name('api-message-reaction.destroy');
        });

        // Notifications...
        Route::get('notifications', [NotificationController::class, 'index'])->name('api-notification.index');
        Route::get('notifications/{notification}/group', [NotificationGroupController::class, 'show'])->name('api-notification-group.show');

        // Remotes: finding people to push to, and starting an org, as the web's New remote and New org do...
        Route::middleware('throttle:conversations')->group(function (): void {
            Route::get('remotes/candidates', [RemoteCandidateController::class, 'index'])->name('api-remote-candidate.index');
            Route::post('remotes/orgs', [OrgController::class, 'store'])->middleware('throttle:messages')->name('api-org.store');
        });
    });
});
