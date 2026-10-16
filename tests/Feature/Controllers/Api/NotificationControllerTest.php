<?php

declare(strict_types=1);

use App\Actions\CreateCommitStar;
use App\Actions\DeleteUserFollow;
use App\Enums\CommitStatus;
use App\Enums\NotificationType;
use App\Models\Commit;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->keyPair = apiKeyPairFor($this->user);
});

it('lists the notifications of the user, newest first', function (): void {
    $followed = Notification::factory()->for($this->user, 'recipient')->create(['created_at' => now()->subMinute()]);
    $replied = Notification::factory()->for($this->user, 'recipient')->replied()->create();
    Notification::factory()->create();

    $response = signedApiRequest($this->keyPair, 'GET', route('api-notification.index'));

    $reply = $replied->commit;

    $user = fn (User $user): array => [
        'id' => $user->id,
        'username' => $user->username,
        'name' => $user->name,
        'avatar_url' => $user->avatar_url,
        'bio' => $user->bio,
        'company' => $user->company,
        'location' => $user->location,
        'website' => $user->website,
        'github_url' => "https://github.com/{$user->username}",
        'html_url' => route('user.show', $user),
        'feed_url' => route('user-feed.show', $user),
        'created_at' => now()->toIso8601ZuluString(),
        'lane' => $user->lane(),
    ];

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0', [
            'id' => $replied->id,
            'type' => 'replied',
            'subject_id' => $reply->parent_id,
            'reason' => null,
            'read_at' => now()->toIso8601ZuluString(),
            'created_at' => now()->toIso8601ZuluString(),
            'actor' => $user($replied->actor),
            'actors' => [$user($replied->actor)],
            'actors_count' => 1,
            'unread_count' => 0,
            'commit' => [
                'id' => $reply->id,
                'short_hash' => mb_substr(str_replace('-', '', $reply->id), -7),
                'body' => $reply->body,
                'parent_id' => $reply->parent_id,
                'html_url' => route('commit.show', [$replied->actor, $reply]),
                'created_at' => now()->toIso8601ZuluString(),
                'author' => $user($replied->actor),
                'body_segments' => [['type' => 'text', 'text' => $reply->body]],
                'mentions' => [],
                'status' => 'approved',
                'is_pinned' => false,
                'deleted_at' => null,
                'parent' => [
                    'id' => $reply->parent->id,
                    'short_hash' => mb_substr(str_replace('-', '', $reply->parent->id), -7),
                    'body' => $reply->parent->body,
                    'parent_id' => null,
                    'html_url' => route('commit.show', [$this->user, $reply->parent]),
                    'created_at' => now()->toIso8601ZuluString(),
                    'author' => $user($this->user),
                    'body_segments' => [['type' => 'text', 'text' => $reply->parent->body]],
                    'mentions' => [],
                    'status' => 'approved',
                    'is_pinned' => false,
                    'deleted_at' => null,
                ],
            ],
        ])
        ->assertJsonPath('data.1.id', $followed->id)
        ->assertJsonPath('data.1.type', 'followed')
        ->assertJsonPath('data.1.commit', null)
        ->assertJsonMissingPath('data.0.key')
        ->assertJsonPath('read_at', now()->toIso8601ZuluString())
        ->assertJsonPath('meta.next_cursor', null);
});

it('hides notifications that no longer hold', function (): void {
    $notification = Notification::factory()->for($this->user, 'recipient')->create();

    resolve(DeleteUserFollow::class)->handle($notification->actor, $this->user);

    $response = signedApiRequest($this->keyPair, 'GET', route('api-notification.index'));

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});

it('shows moderation updates with the reason a commit was rejected', function (): void {
    $commit = Commit::factory()->for($this->user)->rejected()->create();
    Notification::factory()->for($this->user, 'recipient')->create([
        'type' => NotificationType::Rejected,
        'commit_id' => $commit->id,
        'reason' => 'Spam.',
    ]);

    $response = signedApiRequest($this->keyPair, 'GET', route('api-notification.index'));

    $response->assertOk()
        ->assertJsonPath('data.0.type', 'rejected')
        ->assertJsonPath('data.0.reason', 'Spam.')
        ->assertJsonPath('data.0.commit.id', $commit->id)
        ->assertJsonPath('data.0.commit.status', 'rejected');
});

it('hides moderation updates that no longer hold', function (): void {
    $commit = Commit::factory()->for($this->user)->create();
    Notification::factory()->for($this->user, 'recipient')->create([
        'type' => NotificationType::Rejected,
        'commit_id' => $commit->id,
    ]);

    $response = signedApiRequest($this->keyPair, 'GET', route('api-notification.index'));

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});

it('hides mentions in commits that are not approved yet', function (): void {
    $notification = Notification::factory()->for($this->user, 'recipient')->mentioned()->create();
    $notification->commit?->update(['status' => CommitStatus::Pending]);

    $response = signedApiRequest($this->keyPair, 'GET', route('api-notification.index'));

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});

it('hides notifications about deleted commits', function (): void {
    $notification = Notification::factory()->for($this->user, 'recipient')->replied()->create();

    $notification->commit?->delete();

    $response = signedApiRequest($this->keyPair, 'GET', route('api-notification.index'));

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});

it('leaves out the commit a mention replies to when the user may not see it', function (Closure $parent): void {
    $parent = $parent();
    $actor = User::factory()->create();
    $mention = Commit::factory()->for($actor)->for($parent, 'parent')->create(['body' => "Ask @{$this->user->username}"]);
    Notification::factory()->for($this->user, 'recipient')->for($actor, 'actor')->create([
        'type' => NotificationType::Mentioned,
        'commit_id' => $mention->id,
    ]);

    $response = signedApiRequest($this->keyPair, 'GET', route('api-notification.index'));

    $response->assertOk()
        ->assertJsonPath('data.0.type', 'mentioned')
        ->assertJsonPath('data.0.commit.id', $mention->id)
        ->assertJsonPath('data.0.commit.parent_id', $parent->id)
        ->assertJsonPath('data.0.commit.parent', null);
})->with([
    'pending' => fn (): Commit => Commit::factory()->pending()->create(),
    'deleted' => fn (): Commit => tap(Commit::factory()->create())->delete(),
]);

it('marks every notification as read like the web, and says when, so the new ones read since then', function (): void {
    $notification = Notification::factory()->for($this->user, 'recipient')->create();

    signedApiRequest($this->keyPair, 'GET', route('api-notification.index'))
        ->assertJsonPath('read_at', now()->toIso8601ZuluString())
        ->assertJsonPath('data.0.read_at', now()->toIso8601ZuluString());

    expect($notification->refresh()->read_at?->toIso8601ZuluString())->toBe(now()->toIso8601ZuluString());

    $readAt = now()->toIso8601ZuluString();
    $this->travel(1)->minute();

    signedApiRequest($this->keyPair, 'GET', route('api-notification.index'))
        ->assertJsonPath('read_at', now()->toIso8601ZuluString())
        ->assertJsonPath('data.0.read_at', $readAt);
});

it('does not query each notification', function (): void {
    signedApiRequest($this->keyPair, 'GET', route('api-notification.index'))->assertOk();

    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        signedApiRequest($this->keyPair, 'GET', route('api-notification.index'))->assertOk();

        return count(DB::getQueryLog());
    };

    // Two followers make a group with stacked actors, which takes the same queries however many there are...
    Notification::factory()->for($this->user, 'recipient')->count(2)->create();
    Notification::factory()->for($this->user, 'recipient')->replied()->create();
    $few = $queries();

    Notification::factory()->for($this->user, 'recipient')->count(3)->create();
    Notification::factory()->for($this->user, 'recipient')->replied()->count(3)->create();

    expect($queries())->toBe($few);
});

it('groups stars per commit, newest stargazer first, like the web', function (): void {
    $commit = Commit::factory()->for($this->user)->create();
    $stargazers = User::factory()->count(4)->create();

    foreach ($stargazers as $index => $stargazer) {
        $this->travelTo(now()->subMinutes(4 - $index), fn () => resolve(CreateCommitStar::class)->handle($stargazer, $commit));
    }

    $response = signedApiRequest($this->keyPair, 'GET', route('api-notification.index'));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'starred')
        ->assertJsonPath('data.0.subject_id', $commit->id)
        ->assertJsonPath('data.0.actor.id', $stargazers[3]->id)
        ->assertJsonPath('data.0.commit.id', $commit->id)
        ->assertJsonPath('data.0.commit.author.id', $this->user->id)
        ->assertJsonPath('data.0.actors_count', 4)
        ->assertJsonPath('data.0.actors.*.id', [$stargazers[3]->id, $stargazers[2]->id, $stargazers[1]->id])
        ->assertJsonMissingPath('data.0.actors.0.email');

    expect($this->user->notifications()->unread()->exists())->toBeFalse();
});

it('rejects requests that are not signed', function (): void {
    $this->getJson(route('api-notification.index'))->assertUnauthorized();
});
