<?php

declare(strict_types=1);

use App\Actions\CreateTomFriendship;
use App\Enums\CommitStatus;
use App\Models\User;
use Illuminate\Support\Str;

it('makes tom and the user follow each other', function (): void {
    $user = User::factory()->create();

    resolve(CreateTomFriendship::class)->handle($user);

    $tom = User::query()->where('username', 'tom')->sole();

    expect($user->isFollowing($tom))->toBeTrue()
        ->and($tom->isFollowing($user))->toBeTrue()
        ->and($user->notifications()->count())->toBe(0)
        ->and($tom->notifications()->count())->toBe(0);
});

it('creates tom once, with a single welcome commit and no github account', function (): void {
    $action = resolve(CreateTomFriendship::class);

    $action->handle(User::factory()->create());
    $action->handle(User::factory()->create());

    $tom = User::query()->where('username', 'tom')->sole();
    $commit = $tom->commits()->sole();

    expect($commit->body)->toBe('Welcome to dev/null!')
        ->and($commit->status)->toBe(CommitStatus::Approved)
        ->and($tom->hasGitHubAccount())->toBeFalse()
        ->and($tom->following()->count())->toBe(2);
});

it('keeps befriending newcomers under the reserved handle instead of the real tom', function (): void {
    $tom = User::factory()->create(['username' => 'devnull-tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $owner = User::factory()->create(['username' => 'tom']);
    $user = User::factory()->create();

    resolve(CreateTomFriendship::class)->handle($user);

    expect($user->following()->sole()->is($tom))->toBeTrue()
        ->and($tom->following()->sole()->is($user))->toBeTrue()
        ->and($owner->following()->count())->toBe(0)
        ->and($owner->followers()->count())->toBe(0);
});

it('creates tom under fallback handle if username tom is already taken by a real user', function (): void {
    $owner = User::factory()->create(['username' => 'tom']);
    $user = User::factory()->create();

    resolve(CreateTomFriendship::class)->handle($user);

    $tom = User::query()->where('github_id', CreateTomFriendship::GITHUB_ID)->sole();

    expect($tom->username)->toBe('devnull-tom')
        ->and($user->isFollowing($tom))->toBeTrue()
        ->and($tom->isFollowing($user))->toBeTrue()
        ->and($owner->following()->count())->toBe(0);
});

it('does not make tom follow himself or change his existing friendships', function (): void {
    $user = User::factory()->create();
    $action = resolve(CreateTomFriendship::class);

    $action->handle($user);

    $tom = User::query()->where('github_id', CreateTomFriendship::GITHUB_ID)->sole();

    $action->handle($tom);

    expect($tom->isFollowing($tom))->toBeFalse()
        ->and($tom->following()->sole()->is($user))->toBeTrue()
        ->and($tom->followers()->sole()->is($user))->toBeTrue()
        ->and($tom->commits()->count())->toBe(1)
        ->and($tom->notifications()->count())->toBe(0);
});

it('befriends every existing user', function (): void {
    $users = User::factory()->count(3)->create();

    $this->artisan('tom:befriend')->assertSuccessful();

    $tom = User::query()->where('username', 'tom')->sole();

    expect($tom->following()->count())->toBe(3)
        ->and($tom->followers()->count())->toBe(3)
        ->and($tom->isFollowing($tom))->toBeFalse();
});

it('keeps tom out of remotes and orgs', function (): void {
    $user = User::factory()->create();
    [$follower] = followersOf($user, 1);
    $org = createOrg($user, [$follower]);

    resolve(CreateTomFriendship::class)->handle($user);

    $tom = User::query()->where('username', 'tom')->sole();
    $reasons = fn (array $query): ?string => collect($this->getJson(route('remote-candidate.index', ['q' => 'tom', ...$query]))->assertOk()->json('candidates'))
        ->firstWhere('user.username', 'tom')['reason'];

    expect($tom->remotes_enabled)->toBeFalse();

    $this->actingAs($user)->get(route('user.show', $tom))
        ->assertInertia(fn ($page) => $page->where('canPush', false));
    $this->getJson(route('conversation-message.index', $tom))->assertJsonPath('access', 'closed');
    $this->postJson(route('conversation-message.store', $tom), ['body' => 'hi', 'client_id' => Str::uuid()->toString()])
        ->assertForbidden()
        ->assertJsonPath('message', "@tom isn't taking remotes.");

    expect($reasons([]))->toBe('has remotes off')
        ->and($reasons(['for' => 'org']))->toBe('has remotes off')
        ->and($reasons(['org' => $org]))->toBe('has remotes off');

    $this->postJson(route('org.store'), ['members' => ['tom']])
        ->assertUnprocessable()->assertJsonValidationErrors(['members.0' => '@tom has remotes off.']);
    $this->postJson(route('org-member.store', $org), ['members' => ['tom'], 'share_history' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['members.0' => '@tom has remotes off.']);
});

it('turns off the remotes of the tom who already exists', function (): void {
    $user = User::factory()->create();
    resolve(CreateTomFriendship::class)->handle($user);
    $tom = User::query()->where('username', 'tom')->sole();
    $tom->update(['remotes_enabled' => true]);

    $updatedAt = $tom->refresh()->updated_at;

    $migration = require database_path('migrations/2026_10_19_150000_turn_off_remotes_of_tom.php');
    $migration->up();

    expect($tom->refresh()->remotes_enabled)->toBeFalse()
        ->and($tom->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($user->refresh()->remotes_enabled)->toBeTrue();
});

it('renders tom on his profile without a github link', function (): void {
    resolve(CreateTomFriendship::class)->handle(User::factory()->create());

    $this->get('/@tom')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('user/show')
            ->where('user.name', 'Tom')
            ->where('user.has_github_account', false));
});
