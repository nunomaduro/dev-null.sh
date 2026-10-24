<?php

declare(strict_types=1);

use App\Actions\UpdateScheduledCommitSubmittedAt;
use App\Enums\ModerationReason;
use App\Models\Ban;
use App\Models\Commit;
use App\Models\Conversation;
use App\Models\ScheduledCommit;
use App\Models\User;
use App\Models\UserTimeout;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as GitHubUser;

beforeEach(function (): void {
    Queue::fake();
});

it('may put a user in timeout', function (): void {
    $admin = User::factory()->create(['username' => 'admin']);
    $user = User::factory()->create(['username' => 'octocat']);

    config(['app.admins' => ['admin']]);

    $response = $this->actingAs($admin)
        ->fromRoute('user.show', $user)
        ->post(route('user-timeout.store', $user), ['reason' => 'engagement_bait', 'duration' => 1440]);

    $response->assertRedirectToRoute('user.show', $user)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Put @octocat in timeout for 1 day.']);

    expect($user->activeTimeout())
        ->reason->toBe(ModerationReason::EngagementBait)
        ->ends_at->toDateTimeString()->toBe(now()->addDay()->toDateTimeString());
});

it('validates the reason and duration of a timeout', function (): void {
    $admin = User::factory()->create(['username' => 'admin']);
    $user = User::factory()->create();

    config(['app.admins' => ['admin']]);

    $this->actingAs($admin)
        ->post(route('user-timeout.store', $user), ['reason' => 'boredom', 'duration' => 5])
        ->assertSessionHasErrors(['reason', 'duration']);

    expect($user->timeouts()->count())->toBe(0);
});

it('may not be given by someone who is not an admin, nor to an admin', function (string $actor, string $target): void {
    User::factory()->create(['username' => 'admin']);
    User::factory()->create(['username' => 'other-admin']);
    User::factory()->create(['username' => 'someone']);
    User::factory()->create(['username' => 'octocat']);

    config(['app.admins' => ['admin', 'other-admin']]);

    $this->actingAs(User::query()->where('username', $actor)->sole())
        ->post(route('user-timeout.store', ['user' => $target]), ['reason' => 'spam', 'duration' => 60])
        ->assertForbidden();

    $this->delete(route('user-timeout.destroy', ['user' => $target]))->assertForbidden();

    expect(UserTimeout::query()->count())->toBe(0);
})->with([
    'by a user' => ['someone', 'octocat'],
    'to another admin' => ['admin', 'other-admin'],
    'to themselves' => ['admin', 'admin'],
]);

it('ends a timeout early, and still counts it', function (): void {
    $admin = User::factory()->create(['username' => 'admin']);
    $user = User::factory()->create(['username' => 'octocat']);
    UserTimeout::factory()->for($user)->create();
    UserTimeout::factory()->for($user)->create(['ends_at' => now()->addDays(7)]);
    UserTimeout::factory()->for($user)->ended()->create();

    config(['app.admins' => ['admin']]);

    $this->actingAs($admin)
        ->fromRoute('user.show', $user)
        ->delete(route('user-timeout.destroy', $user))
        ->assertRedirectToRoute('user.show', $user)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "Ended @octocat's timeout."]);

    expect($user->activeTimeout())->toBeNull()
        ->and($user->timeouts()->count())->toBe(3);
});

it('shows admins the timeouts and ban of a user on their profile', function (): void {
    $admin = User::factory()->create(['username' => 'admin']);
    $user = User::factory()->create(['username' => 'octocat']);
    UserTimeout::factory()->for($user)->create(['reason' => ModerationReason::LowEffort, 'ends_at' => now()->addHour()]);
    UserTimeout::factory()->for($user)->ended()->create();

    config(['app.admins' => ['admin']]);

    $this->actingAs($admin)
        ->get(route('user.show', $user))
        ->assertInertia(fn ($page) => $page
            ->where('moderation.timeoutsCount', 2)
            ->where('moderation.timeout.reason', 'Low-effort posts')
            ->where('moderation.ban', null)
            ->where('moderation.reasons.ai_generated', 'AI-generated posts')
            ->where('moderation.durations.60', '1 hour'));

    $user->ban()->create(['reason' => ModerationReason::Spam]);

    $this->get(route('user.show', $user))
        ->assertInertia(fn ($page) => $page
            ->where('moderation.ban.reason', 'Spam or self-promotion'));
});

it('hides moderation from everyone but admins, and from the profiles of admins', function (string $viewer, string $profile): void {
    User::factory()->create(['username' => 'admin']);
    User::factory()->create(['username' => 'other-admin']);
    User::factory()->create(['username' => 'someone']);
    $user = User::factory()->create(['username' => 'octocat']);
    UserTimeout::factory()->for($user)->create();

    config(['app.admins' => ['admin', 'other-admin']]);

    $this->actingAs(User::query()->where('username', $viewer)->sole())
        ->get(route('user.show', ['user' => $profile]))
        ->assertInertia(fn ($page) => $page->where('moderation', null));
})->with([
    'a user' => ['someone', 'octocat'],
    'the user themselves' => ['octocat', 'octocat'],
    'an admin' => ['admin', 'other-admin'],
]);

it('stops a user in timeout from posting on the web', function (): void {
    $user = User::factory()->create();
    UserTimeout::factory()->for($user)->create(['reason' => ModerationReason::EngagementBait, 'ends_at' => now()->addHours(3)]);
    $parent = Commit::factory()->create();
    $timeout = [
        'reason' => 'Engagement bait',
        'remaining' => '3 hours',
        'ends_at' => now()->addHours(3)->startOfSecond()->toIso8601ZuluString(),
    ];

    $this->actingAs($user)
        ->fromRoute('home')
        ->post(route('commit.store'), ['body' => 'Like if you agree'])
        ->assertRedirectToRoute('home')
        ->assertInertiaFlash('timeout', $timeout)
        ->assertInertiaFlashMissing('toast');

    $this->post(route('scheduled-commit.store'), ['body' => 'Like if you agree', 'scheduled_at' => now()->addHour()->toIso8601String()])
        ->assertInertiaFlash('timeout', $timeout);

    $this->postJson(route('commit-reply.store', $parent), ['body' => 'Like if you agree'])
        ->assertForbidden()
        ->assertExactJson([
            'message' => "You're in timeout for 3 hours (Engagement bait). You can read, but you can't post until it's over.",
            'timeout' => $timeout,
        ]);

    expect($user->commits()->count())->toBe(0)
        ->and(ScheduledCommit::query()->count())->toBe(0);
});

it('stops a user in timeout from sending remotes', function (): void {
    $user = User::factory()->withRemotes()->create();
    $partner = User::factory()->withRemotes()->create();
    UserTimeout::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson(route('conversation-message.store', $partner), ['body' => 'hello', 'client_id' => Str::uuid()->toString()])
        ->assertForbidden()
        ->assertJsonPath('timeout.reason', 'Spam or self-promotion');
});

it('stops a user in timeout from starting, renaming, or growing orgs, and from messaging or pinning in them', function (): void {
    $user = User::factory()->create();
    [$member, $newcomer] = followersOf($user, 2);
    $org = createOrg($user, [$member]);
    $message = pushToOrg($member, $org);
    UserTimeout::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson(route('org.store'), ['name' => 'buy my course', 'members' => [$member->username]])
        ->assertForbidden()
        ->assertJsonPath('timeout.reason', 'Spam or self-promotion');

    $this->putJson(route('org.update', $org), ['name' => 'buy my course'])->assertForbidden();
    $this->postJson(route('org-member.store', $org), ['members' => [$newcomer->username], 'share_history' => false])->assertForbidden();
    $this->postJson(route('org-message.store', $org), ['body' => 'hello', 'client_id' => Str::uuid()->toString()])->assertForbidden();
    $this->putJson(route('message-pin.update', $message))->assertForbidden();

    expect(Conversation::query()->count())->toBe(1)
        ->and(Conversation::query()->sole()->name)->toBe('dev/null core')
        ->and(Conversation::query()->sole()->participants()->count())->toBe(2)
        ->and($message->fresh()?->pinned_at)->toBeNull();
});

it('tells the user how long is left of the timeout that ends last, rounded up', function (string $endsIn, string $remaining): void {
    $user = User::factory()->create();
    UserTimeout::factory()->for($user)->create(['ends_at' => now()->addMinutes(30)]);
    UserTimeout::factory()->for($user)->create(['ends_at' => now()->modify($endsIn)]);

    expect($user->activeTimeout()?->details()['remaining'])->toBe($remaining);
})->with([
    ['+3 hours', '3 hours'],
    ['+59 minutes 30 seconds', '1 hour'],
    ['+1 hour 30 minutes 10 seconds', '1 hour 31 minutes'],
    ['+2 days 30 seconds', '2 days 1 hour'],
    ['+29 days 23 hours 59 minutes', '30 days'],
]);

it('lets a user post again once the timeout is over', function (): void {
    $user = User::factory()->create();
    UserTimeout::factory()->for($user)->create(['ends_at' => now()->addHour()]);

    $this->travel(61)->minutes();

    $this->actingAs($user)
        ->post(route('commit.store'), ['body' => 'Back to shipping.'])
        ->assertSessionHasNoErrors();

    expect($user->commits()->count())->toBe(1);
});

it('stops a user in timeout from posting from the app', function (): void {
    $user = User::factory()->create();
    UserTimeout::factory()->for($user)->create(['reason' => ModerationReason::Spam, 'ends_at' => now()->addDays(2)]);

    signedApiRequest(apiKeyPairFor($user), 'POST', route('api-commit.store'), ['body' => 'It was DNS.'])
        ->assertForbidden()
        ->assertJson([
            'code' => 'timed_out',
            'message' => "You're in timeout for 2 days (Spam or self-promotion). You can read, but you can't post until it's over.",
        ]);

    expect($user->commits()->count())->toBe(0);
});

it('holds the scheduled commits of a user in timeout until it is over', function (): void {
    $user = User::factory()->create();
    $scheduled = ScheduledCommit::factory()->for($user)->create(['scheduled_at' => now()->subMinute()]);
    UserTimeout::factory()->for($user)->create(['ends_at' => now()->addHour()]);

    $this->artisan('commits:submit-scheduled')->assertSuccessful();

    expect($scheduled->refresh()->submitted_at)->toBeNull();

    $this->travel(61)->minutes();

    $this->artisan('commits:submit-scheduled')->assertSuccessful();

    expect($scheduled->refresh()->submitted_at)->not->toBeNull();
});

it('does not submit a scheduled commit whose author was put in timeout after the batch was selected', function (): void {
    $first = ScheduledCommit::factory()->create(['scheduled_at' => now()->subMinutes(2)]);
    $user = User::factory()->create();
    $second = ScheduledCommit::factory()->for($user)->create(['scheduled_at' => now()->subMinute()]);

    // The timeout lands while the first item of the batch is being submitted, before the second is claimed...
    Commit::created(function (Commit $commit) use ($first, $user): void {
        if ($commit->user_id === $first->user_id) {
            UserTimeout::factory()->for($user)->create(['ends_at' => now()->addHour()]);
        }
    });

    try {
        $this->artisan('commits:submit-scheduled')->assertSuccessful();
    } finally {
        Commit::flushEventListeners();
    }

    expect($first->refresh()->submitted_at)->not->toBeNull()
        ->and($second->refresh()->submitted_at)->toBeNull()
        ->and($user->commits()->count())->toBe(0);

    $this->travel(61)->minutes();

    $this->artisan('commits:submit-scheduled')->assertSuccessful();

    expect($second->refresh()->submitted_at)->not->toBeNull()
        ->and($user->commits()->count())->toBe(1);
});

it('does not submit a scheduled commit whose author was banned after the batch was selected', function (): void {
    $user = User::factory()->create();
    $scheduled = ScheduledCommit::factory()->for($user)->create(['scheduled_at' => now()->subMinute()]);
    Ban::factory()->create(['github_id' => $user->github_id]);

    expect(resolve(UpdateScheduledCommitSubmittedAt::class)->handle($scheduled->id))->toBeFalse()
        ->and($scheduled->refresh()->submitted_at)->toBeNull()
        ->and($user->commits()->count())->toBe(0);
});

it('keeps the timeouts of a deleted account, so signing up again neither ends one nor resets the count', function (): void {
    $user = User::factory()->create(['github_id' => '583231']);
    UserTimeout::factory()->for($user)->create(['ends_at' => now()->addDay()]);
    UserTimeout::factory()->for($user)->ended()->create();

    $this->actingAs($user)->delete(route('user.destroy'))->assertRedirectToRoute('home');

    expect(UserTimeout::query()->count())->toBe(2);

    Socialite::fake('github', GitHubUser::fake(['id' => '583231', 'nickname' => 'octocat']));
    fakeGitHubSponsorsListing();

    $this->get(route('login.store'))->assertRedirect();

    $again = User::query()->where('github_id', '583231')->sole();

    expect($again->is($user))->toBeFalse()
        ->and($again->activeTimeout())->not->toBeNull()
        ->and($again->timeouts()->count())->toBe(2);
});
