<?php

declare(strict_types=1);

use App\Enums\Log;
use App\Http\Controllers\AboutController;
use App\Http\Controllers\BanController;
use App\Http\Controllers\BannedController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\BulkActivityController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\Cli\ScriptController;
use App\Http\Controllers\CommitApprovalController;
use App\Http\Controllers\CommitContinuationController;
use App\Http\Controllers\CommitController;
use App\Http\Controllers\CommitImageController;
use App\Http\Controllers\CommitIntentController;
use App\Http\Controllers\CommitLinkPreviewController;
use App\Http\Controllers\CommitOpenGraphImageController;
use App\Http\Controllers\CommitPastelController;
use App\Http\Controllers\CommitPhotoController;
use App\Http\Controllers\CommitPinController;
use App\Http\Controllers\CommitPollVoteController;
use App\Http\Controllers\CommitPreviewController;
use App\Http\Controllers\CommitRejectionController;
use App\Http\Controllers\CommitReplyController;
use App\Http\Controllers\CommitReportController;
use App\Http\Controllers\CommitRestorationController;
use App\Http\Controllers\CommitSearchController;
use App\Http\Controllers\CommitSitemapController;
use App\Http\Controllers\CommitStarController;
use App\Http\Controllers\CommitStarCountController;
use App\Http\Controllers\CommitStatusController;
use App\Http\Controllers\CommitTranslationController;
use App\Http\Controllers\ConversationArchiveController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\ConversationDropController;
use App\Http\Controllers\ConversationMergeController;
use App\Http\Controllers\ConversationMessageController;
use App\Http\Controllers\ConversationMuteController;
use App\Http\Controllers\ConversationPinController;
use App\Http\Controllers\ConversationReadController;
use App\Http\Controllers\ConversationTypingController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\EmailDraftController;
use App\Http\Controllers\EmailPreviewController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FailedJobController;
use App\Http\Controllers\FailedJobRetryController;
use App\Http\Controllers\FailedJobsController;
use App\Http\Controllers\FeatureController;
use App\Http\Controllers\ForecastController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\IssueSuggestionController;
use App\Http\Controllers\LocalSessionController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MentionController;
use App\Http\Controllers\MentionSuggestionController;
use App\Http\Controllers\MessageImageController;
use App\Http\Controllers\MessageLinkPreviewController;
use App\Http\Controllers\MessageReactionController;
use App\Http\Controllers\MessageVoiceController;
use App\Http\Controllers\MobileAuthorizationController;
use App\Http\Controllers\ModeratedUserController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationGroupController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\OperationsEventController;
use App\Http\Controllers\OrgArchiveController;
use App\Http\Controllers\OrgController;
use App\Http\Controllers\OrgMemberController;
use App\Http\Controllers\OrgMessageController;
use App\Http\Controllers\OrgMuteController;
use App\Http\Controllers\OrgPinController;
use App\Http\Controllers\OrgReadController;
use App\Http\Controllers\OrgTypingController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\ProductMetricsController;
use App\Http\Controllers\RemoteCandidateController;
use App\Http\Controllers\ReportedCommitController;
use App\Http\Controllers\SafetyController;
use App\Http\Controllers\ScheduledCommitController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SiteActivityController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StarredCommitController;
use App\Http\Controllers\SuggestionDismissalController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\TermsController;
use App\Http\Controllers\TestEmailController;
use App\Http\Controllers\TrafficController;
use App\Http\Controllers\UnsubscriptionController;
use App\Http\Controllers\UserActivityGraphController;
use App\Http\Controllers\UserAutomaticTranslationController;
use App\Http\Controllers\UserBanController;
use App\Http\Controllers\UserBlockController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserFeedController;
use App\Http\Controllers\UserFollowController;
use App\Http\Controllers\UserFollowersController;
use App\Http\Controllers\UserFollowingController;
use App\Http\Controllers\UserLaneController;
use App\Http\Controllers\UserLiveController;
use App\Http\Controllers\UserMuteController;
use App\Http\Controllers\UserOpenGraphImageController;
use App\Http\Controllers\UserPasteisModeController;
use App\Http\Controllers\UserPreviewController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\UserRemotePreferencesController;
use App\Http\Controllers\UserRemotesController;
use App\Http\Controllers\UserSearchController;
use App\Http\Controllers\UserSitemapController;
use App\Http\Controllers\UserTimeoutController;
use App\Http\Controllers\UserTimezoneController;
use App\Http\Controllers\UserTranslationLanguageController;
use App\Http\Middleware\PreventPostingInTimeout;
use App\Http\Middleware\RememberIntendedUrl;
use App\Http\Middleware\RequireCommitRules;
use App\Http\Middleware\ThrottleCommits;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Commit from the terminal: curl -fsSL https://dev-null.sh/commit | bash -s -- 'Hello world!'
Route::get('commit', [ScriptController::class, 'show'])->name('cli-script.show');

Route::middleware('guest')->group(function (): void {
    // Guests land straight on the Trending log...
    Route::get('/', [LogController::class, 'index'])->name('home');

    // Session...
    if (app()->isLocal()) {
        // Locally, signing in asks for any username in a modal, without GitHub...
        Route::get('login', [LocalSessionController::class, 'create'])->name('login');
        Route::post('login/local', [LocalSessionController::class, 'store'])->name('local-login.store');
    } else {
        Route::get('login', [SessionController::class, 'create'])
            ->middleware('throttle:10,1')
            ->name('login');
    }

    Route::get('auth/github/callback', [SessionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('login.store');
});

// Log, where guests read Trending and Latest...
Route::get('log/everyone', fn () => to_route('log.show', Log::Trending, 301));
Route::get('log/{log}', [LogController::class, 'show'])->name('log.show');

// Terms and privacy, readable by everyone...
Route::get('terms', [TermsController::class, 'show'])->name('terms.show');
Route::get('privacy', [PrivacyController::class, 'show'])->name('privacy.show');

// Unsubscribing from the admins' emails, from the signed link in each one...
Route::get('emails/unsubscribe/{user}', [UnsubscriptionController::class, 'show'])->whereUuid('user')->name('unsubscription.show');
Route::post('emails/unsubscribe/{user}', [UnsubscriptionController::class, 'store'])
    ->whereUuid('user')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('unsubscription.store');

// How /dev/null was built, readable by everyone...
Route::get('about', [AboutController::class, 'show'])->name('about.show');

// Logos, colors, and backgrounds, downloadable by everyone...
Route::get('brand', [BrandController::class, 'show'])->name('brand.show');

// What was merged into production, readable by everyone...
Route::get('changelog', [ChangelogController::class, 'index'])->name('changelog.index');

// Public pages, readable by guests and crawlers...
Route::middleware(RememberIntendedUrl::class)->group(function (): void {
    Route::get('@{user:username}', [UserController::class, 'show'])->name('user.show');
    Route::get('@{user:username}/following', [UserFollowingController::class, 'index'])
        ->name('user-following.index');
    Route::get('@{user:username}/followers', [UserFollowersController::class, 'index'])
        ->name('user-followers.index');
    Route::get('@{user:username}/commits/{commit}', [CommitController::class, 'show'])
        ->scopeBindings()
        ->name('commit.show');
    Route::get('@{user:username}/commits/{commit}/photos/{photo}', [CommitPhotoController::class, 'show'])
        ->scopeBindings()
        ->whereNumber('photo')
        ->name('commit-photo.show');
});

// Sitemaps...
Route::middleware('cache.headers:public;max_age=3600;etag')->group(function (): void {
    Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');
    Route::get('sitemaps/users-{page}.xml', [UserSitemapController::class, 'show'])
        ->where('page', '[1-9][0-9]*')
        ->name('user-sitemap.show');
    Route::get('sitemaps/commits-{page}.xml', [CommitSitemapController::class, 'show'])
        ->where('page', '[1-9][0-9]*')
        ->name('commit-sitemap.show');
});

// User...
Route::get('mentions/{user}', [MentionController::class, 'show'])
    ->whereUuid('user')
    ->name('mention.show');
Route::get('mentions/{user}/preview', [UserPreviewController::class, 'show'])
    ->middleware('throttle:people')
    ->whereUuid('user')
    ->name('mention.preview');
Route::get('@{user:username}/preview', [UserPreviewController::class, 'show'])
    ->middleware('throttle:people')
    ->name('user-preview.show');
Route::get('@{user:username}/og.png', [UserOpenGraphImageController::class, 'show'])
    ->name('user-open-graph-image.show');
Route::get('@{user:username}/feed.atom', [UserFeedController::class, 'show'])
    ->middleware('cache.headers:public;max_age=300;etag')
    ->name('user-feed.show');

// User activity graph, embedded in READMEs...
Route::get('@{user:username}/graph.svg', [UserActivityGraphController::class, 'show'])
    ->middleware(['throttle:activity-graph', 'cache.headers:public;max_age=3600;etag'])
    ->name('user-activity-graph.show');

// Commit...
Route::get('@{user:username}/commits/{commit}/og.png', [CommitOpenGraphImageController::class, 'show'])
    ->scopeBindings()
    ->name('commit-open-graph-image.show');
Route::get('commit-images/{commitImage}', [CommitImageController::class, 'show'])
    ->whereUuid('commitImage')
    ->name('commit-image.show');
Route::get('commits/{commit}/link-preview', [CommitLinkPreviewController::class, 'show'])
    ->name('commit-link-preview.show');
Route::get('commits/{commit}/status', [CommitStatusController::class, 'show'])
    ->name('commit-status.show');
Route::get('commits/{commit}/replies', [CommitReplyController::class, 'index'])
    ->withTrashed()
    ->name('commit-reply.index');
Route::get('commits/{commit}/continuation', [CommitContinuationController::class, 'index'])
    ->name('commit-continuation.index');

// Search...
Route::get('search', [SearchController::class, 'index'])
    ->name('search.index');

// User Search...
Route::get('search/users', [UserSearchController::class, 'index'])
    ->middleware('throttle:people')
    ->name('user-search.index');

// Commit Search...
Route::get('search/commits', [CommitSearchController::class, 'index'])
    ->middleware('throttle:search')
    ->name('commit-search.index');

Route::middleware('auth')->group(function (): void {
    Route::post('issues', [IssueController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('issue.store');

    // Session...
    Route::post('logout', [SessionController::class, 'destroy'])
        ->name('logout');

    // Banned, where a banned user is sent from everywhere else...
    Route::get('banned', [BannedController::class, 'show'])->name('banned.show');

    // Onboarding...
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::put('onboarding', [OnboardingController::class, 'update'])->name('onboarding.update');

    // Sharing to /dev/null from elsewhere...
    Route::get('intent/commit', [CommitIntentController::class, 'show'])->name('commit-intent.show');

    // Private starred commits...
    Route::get('starred', [StarredCommitController::class, 'index'])->name('starred.index');

    // Commit...
    Route::get('scheduled-commits', [ScheduledCommitController::class, 'index'])->name('scheduled-commit.index');
    Route::post('scheduled-commits', [ScheduledCommitController::class, 'store'])
        ->middleware([PreventPostingInTimeout::class, RequireCommitRules::class, ThrottleCommits::class])->name('scheduled-commit.store');
    Route::delete('scheduled-commits/{scheduledCommit}', [ScheduledCommitController::class, 'destroy'])->name('scheduled-commit.destroy');

    Route::get('commit-stars', [CommitStarCountController::class, 'index'])->name('commit-star-count.index');
    Route::post('commits', [CommitController::class, 'store'])
        ->middleware([PreventPostingInTimeout::class, RequireCommitRules::class, ThrottleCommits::class])
        ->name('commit.store');
    Route::post('commits/preview', [CommitPreviewController::class, 'store'])
        ->name('commit-preview.store');
    Route::post('commits/{commit}/translation', [CommitTranslationController::class, 'store'])
        ->middleware('throttle:10,1,translations:')
        ->name('commit-translation.store');
    Route::delete('commits/{commit}', [CommitController::class, 'destroy'])
        ->name('commit.destroy');
    Route::post('commits/{commit}/restoration', [CommitRestorationController::class, 'store'])
        ->withTrashed()
        ->name('commit-restoration.store');
    Route::put('commits/{commit}/pin', [CommitPinController::class, 'update'])
        ->middleware('throttle:pins')
        ->name('commit-pin.update');
    Route::delete('commits/{commit}/pin', [CommitPinController::class, 'destroy'])
        ->middleware('throttle:pins')
        ->name('commit-pin.destroy');
    Route::get('commits/{commit}/stars', [CommitStarController::class, 'index'])
        ->name('commit-star.index');
    Route::post('commits/{commit}/star', [CommitStarController::class, 'store'])
        ->middleware('throttle:stars')
        ->name('commit-star.store');
    Route::post('commits/{commit}/pasteis', [CommitPastelController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('commit-pastel.store');
    Route::post('commits/{commit}/reports', [CommitReportController::class, 'store'])
        ->middleware('throttle:commit-reports')
        ->name('commit-report.store');
    Route::delete('commits/{commit}/star', [CommitStarController::class, 'destroy'])
        ->middleware('throttle:stars')
        ->name('commit-star.destroy');
    Route::post('commits/{commit}/replies', [CommitReplyController::class, 'store'])
        ->middleware([PreventPostingInTimeout::class, RequireCommitRules::class, ThrottleCommits::class])
        ->name('commit-reply.store');
    Route::delete('commits/{commit}/replies/{reply}', [CommitReplyController::class, 'destroy'])
        ->withTrashed()
        ->name('commit-reply.destroy');
    Route::post('commits/{commit}/poll/votes', [CommitPollVoteController::class, 'store'])
        ->name('commit-poll-vote.store');

    // Notifications...
    Route::get('notifications', [NotificationController::class, 'index'])->name('notification.index');
    Route::get('notifications/{notification}/group', [NotificationGroupController::class, 'show'])->name('notification-group.show');

    // Messages...
    Route::get('remotes', [ConversationController::class, 'index'])->name('conversation.index');
    Route::get('remotes/@{partner:username}', [ConversationController::class, 'show'])->name('conversation.show');
    Route::get('remotes/orgs/{org}', [OrgController::class, 'show'])->whereUuid('org')->name('org.show');
    Route::get('remotes/images/{messageImage}', [MessageImageController::class, 'show'])->whereUuid('messageImage')->name('message-image.show');
    Route::get('remotes/voices/{messageVoice}', [MessageVoiceController::class, 'show'])->whereUuid('messageVoice')->name('message-voice.show');

    Route::middleware('throttle:conversations')->group(function (): void {
        Route::get('remotes/inbox', [InboxController::class, 'index'])->name('inbox.index');
        Route::get('remotes/@{partner:username}/messages', [ConversationMessageController::class, 'index'])
            ->name('conversation-message.index');
        Route::put('remotes/@{partner:username}/read', [ConversationReadController::class, 'update'])
            ->name('conversation-read.update');
        Route::put('remotes/@{partner:username}/typing', [ConversationTypingController::class, 'update'])
            ->name('conversation-typing.update');
        Route::post('remotes/@{partner:username}/merge', [ConversationMergeController::class, 'store'])
            ->name('conversation-merge.store');
        Route::post('remotes/@{partner:username}/drop', [ConversationDropController::class, 'store'])
            ->name('conversation-drop.store');
        Route::put('remotes/preferences', [UserRemotePreferencesController::class, 'update'])
            ->name('user-remote-preferences.update');
        Route::get('remotes/candidates', [RemoteCandidateController::class, 'index'])->name('remote-candidate.index');
        Route::post('remotes/orgs', [OrgController::class, 'store'])->middleware([PreventPostingInTimeout::class, 'throttle:messages'])->name('org.store');
        Route::put('remotes/orgs/{org}', [OrgController::class, 'update'])->whereUuid('org')->middleware(PreventPostingInTimeout::class)->name('org.update');
        Route::get('remotes/orgs/{org}/messages', [OrgMessageController::class, 'index'])->whereUuid('org')->name('org-message.index');
        Route::put('remotes/orgs/{org}/read', [OrgReadController::class, 'update'])->whereUuid('org')->name('org-read.update');
        Route::put('remotes/orgs/{org}/typing', [OrgTypingController::class, 'update'])->whereUuid('org')->name('org-typing.update');
        Route::post('remotes/orgs/{org}/archive', [OrgArchiveController::class, 'store'])->whereUuid('org')->name('org-archive.store');
        Route::delete('remotes/orgs/{org}/archive', [OrgArchiveController::class, 'destroy'])->whereUuid('org')->name('org-archive.destroy');
        Route::put('remotes/orgs/{org}/mute', [OrgMuteController::class, 'update'])->whereUuid('org')->name('org-mute.update');
        Route::put('remotes/orgs/{org}/pin', [OrgPinController::class, 'update'])->whereUuid('org')->name('org-pin.update');
        Route::post('remotes/orgs/{org}/members', [OrgMemberController::class, 'store'])->whereUuid('org')->middleware([PreventPostingInTimeout::class, 'throttle:messages'])->name('org-member.store');
        Route::put('remotes/orgs/{org}/members/{member:username}', [OrgMemberController::class, 'update'])->whereUuid('org')->withoutScopedBindings()->name('org-member.update');
        Route::delete('remotes/orgs/{org}/members/{member:username}', [OrgMemberController::class, 'destroy'])->whereUuid('org')->withoutScopedBindings()->name('org-member.destroy');
        Route::post('remotes/@{partner:username}/archive', [ConversationArchiveController::class, 'store'])
            ->name('conversation-archive.store');
        Route::delete('remotes/@{partner:username}/archive', [ConversationArchiveController::class, 'destroy'])
            ->name('conversation-archive.destroy');
        Route::put('remotes/@{partner:username}/mute', [ConversationMuteController::class, 'update'])
            ->name('conversation-mute.update');
        Route::put('remotes/@{partner:username}/pin', [ConversationPinController::class, 'update'])
            ->name('conversation-pin.update');
        Route::get('remotes/messages/{message}/link-preview', [MessageLinkPreviewController::class, 'show'])
            ->whereNumber('message')
            ->name('message-link-preview.show');
        Route::get('remotes/reactions', [MessageReactionController::class, 'index'])->name('message-reaction.index');
        Route::post('remotes/messages/{message}/reactions', [MessageReactionController::class, 'store'])
            ->whereNumber('message')
            ->name('message-reaction.store');
        Route::delete('remotes/messages/{message}/reactions', [MessageReactionController::class, 'destroy'])
            ->whereNumber('message')
            ->name('message-reaction.destroy');
    });

    Route::post('remotes/@{partner:username}/messages', [ConversationMessageController::class, 'store'])
        ->middleware([PreventPostingInTimeout::class, 'throttle:messages'])
        ->name('conversation-message.store');
    Route::post('remotes/orgs/{org}/messages', [OrgMessageController::class, 'store'])
        ->whereUuid('org')
        ->middleware([PreventPostingInTimeout::class, 'throttle:messages'])
        ->name('org-message.store');

    // Moderation...
    Route::get('moderation', [ModerationController::class, 'show'])->name('moderation.show');
    Route::get('moderation/reports', [ReportedCommitController::class, 'index'])->name('reported-commit.index');
    Route::delete('moderation/reports/{report}', [CommitReportController::class, 'destroy'])->name('commit-report.destroy');
    Route::post('moderation/{commit}/approval', [CommitApprovalController::class, 'store'])
        ->name('commit-approval.store');
    Route::post('moderation/{commit}/rejection', [CommitRejectionController::class, 'store'])
        ->name('commit-rejection.store');
    Route::put('moderation', [ModerationController::class, 'update'])->name('moderation.update');
    Route::get('moderation/people', [ModeratedUserController::class, 'index'])->name('moderated-user.index');
    Route::get('moderation/bulk', [BulkActivityController::class, 'index'])->name('bulk-activity.index');
    Route::delete('moderation/bans/{ban}', [BanController::class, 'destroy'])->whereUuid('ban')->name('ban.destroy');

    // Operations...
    Route::get('operations', [OperationsController::class, 'show'])->name('operations.show');
    Route::get('operations/health', [SystemHealthController::class, 'show'])->name('system-health.show');
    Route::get('operations/events', [OperationsEventController::class, 'index'])->name('operations-event.index');
    Route::get('operations/forecasts', [ForecastController::class, 'show'])->name('forecast.show');
    Route::get('operations/features', [FeatureController::class, 'index'])->name('feature.index');
    Route::get('operations/activity', [SiteActivityController::class, 'show'])->name('site-activity.show');
    Route::get('operations/traffic', [TrafficController::class, 'show'])->name('traffic.show');
    Route::get('operations/metrics', [ProductMetricsController::class, 'show'])->name('product-metrics.show');
    Route::post('operations/failed-jobs/{failedJob}/retry', [FailedJobRetryController::class, 'store'])
        ->whereUuid('failedJob')
        ->name('failed-job-retry.store');
    Route::delete('operations/failed-jobs', [FailedJobsController::class, 'destroy'])->name('failed-jobs.destroy');
    Route::delete('operations/failed-jobs/{failedJob}', [FailedJobController::class, 'destroy'])
        ->whereUuid('failedJob')
        ->name('failed-job.destroy');
    Route::put('operations/features/{feature}', [FeatureController::class, 'update'])->name('feature.update');
    Route::get('operations/emails', [EmailController::class, 'create'])->name('email.create');
    Route::post('operations/emails', [EmailController::class, 'store'])->name('email.store');
    Route::post('operations/emails/test', [TestEmailController::class, 'store'])->name('test-email.store');
    Route::post('operations/emails/preview', [EmailPreviewController::class, 'store'])->name('email-preview.store');
    Route::post('operations/emails/draft', [EmailDraftController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('email-draft.store');

    // Mention Suggestions...
    Route::get('search/mentions', [MentionSuggestionController::class, 'index'])
        ->middleware('throttle:people')
        ->name('mention-suggestion.index');

    Route::get('search/issues', [IssueSuggestionController::class, 'index'])
        ->middleware('throttle:issue-suggestions')
        ->name('issue-suggestion.index');

    // User Profile...
    Route::get('settings', fn () => to_route('user-profile.edit'));
    Route::get('settings/profile', [UserProfileController::class, 'edit'])->name('user-profile.edit');
    Route::put('settings/profile', [UserProfileController::class, 'update'])
        ->middleware('throttle:profile-syncs')
        ->name('user-profile.update');
    Route::get('settings/export', ExportController::class)
        ->middleware(['throttle:exports', 'throttle:5,1'])
        ->name('export');

    // User Lane...
    Route::put('settings/lane', [UserLaneController::class, 'update'])->name('user-lane.update');

    // User Timezone...
    Route::put('settings/timezone', [UserTimezoneController::class, 'update'])->name('user-timezone.update');

    Route::put('settings/automatic-translation', [UserAutomaticTranslationController::class, 'update'])->name('user-automatic-translation.update');
    Route::put('settings/translation-language', [UserTranslationLanguageController::class, 'update'])->name('user-translation-language.update');

    Route::put('settings/pasteis-mode', [UserPasteisModeController::class, 'update'])->name('user-pasteis-mode.update');

    Route::put('settings/remotes', [UserRemotesController::class, 'update'])->name('user-remotes.update');

    // User Live...
    Route::put('settings/live', [UserLiveController::class, 'update'])->name('user-live.update');
    Route::delete('settings/live', [UserLiveController::class, 'destroy'])->name('user-live.destroy');

    // Appearance...
    Route::get('settings/appearance', fn () => Inertia::render('appearance/update'))->name('appearance.edit');

    // Terminal...
    Route::get('settings/terminal', fn () => Inertia::render('terminal/show', ['url' => url('/')]))->name('terminal.show');

    // Safety...
    Route::get('settings/safety', [SafetyController::class, 'edit'])->name('safety.edit');

    // Devices...
    Route::get('settings/devices', [DeviceController::class, 'index'])->name('device.index');
    Route::delete('settings/devices/{device}', [DeviceController::class, 'destroy'])->name('device.destroy');

    // Mobile Authorization...
    Route::get('mobile/authorize', [MobileAuthorizationController::class, 'show'])->name('mobile-authorization.show');
    Route::post('mobile/authorize', [MobileAuthorizationController::class, 'store'])
        ->middleware('throttle:mobile-authorizations')
        ->name('mobile-authorization.store');

    // User...
    Route::delete('user', [UserController::class, 'destroy'])->name('user.destroy');

    // User Follow...
    Route::post('@{user:username}/follow', [UserFollowController::class, 'store'])
        ->middleware('throttle:follows')
        ->name('user-follow.store');
    Route::delete('@{user:username}/follow', [UserFollowController::class, 'destroy'])
        ->middleware('throttle:follows')
        ->name('user-follow.destroy');

    // User Mute...
    Route::post('@{user:username}/mute', [UserMuteController::class, 'store'])
        ->name('user-mute.store');
    Route::delete('@{user:username}/mute', [UserMuteController::class, 'destroy'])
        ->name('user-mute.destroy');

    // User Block...
    Route::post('@{user:username}/block', [UserBlockController::class, 'store'])
        ->name('user-block.store');
    Route::delete('@{user:username}/block', [UserBlockController::class, 'destroy'])
        ->name('user-block.destroy');

    // Suggestion Dismissal...
    Route::post('@{user:username}/dismiss', [SuggestionDismissalController::class, 'store'])
        ->name('suggestion-dismissal.store');

    // User Timeout, given by moderators...
    Route::post('@{user:username}/timeout', [UserTimeoutController::class, 'store'])
        ->name('user-timeout.store');
    Route::delete('@{user:username}/timeout', [UserTimeoutController::class, 'destroy'])
        ->name('user-timeout.destroy');

    // User Ban, given by moderators...
    Route::post('@{user:username}/ban', [UserBanController::class, 'store'])
        ->name('user-ban.store');
    Route::delete('@{user:username}/ban', [UserBanController::class, 'destroy'])
        ->name('user-ban.destroy');
});

Route::fallback(fn () => abort(404));
