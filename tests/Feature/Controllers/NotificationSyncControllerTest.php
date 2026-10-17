<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Enums\NotificationType;
use App\Models\Commit;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('requires authentication for notification sync', function (): void {
    $this->getJson(route('notification-sync.index'))->assertUnauthorized();
});

it('returns a private bounded sync payload with the existing group shape', function (): void {
    $user = User::factory()->create();
    $groups = collect(range(1, 62))->map(fn (int $minutes): Notification => Notification::factory()
        ->for($user, 'recipient')
        ->replied()
        ->create(['created_at' => now()->subMinutes($minutes)]));
    $visibleIds = $groups->slice(21, 20)->pluck('id')->all();

    $response = $this->actingAs($user)->getJson(route('notification-sync.index', [
        'visible' => $visibleIds,
    ]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $response->assertJsonCount(20, 'head')
        ->assertJsonPath('boundary', [
            'group_id' => $groups[20]->id,
            'id' => $groups[20]->id,
        ])
        ->assertJsonCount(20, 'visible')
        ->assertJsonPath('missing_visible_group_ids', [])
        ->assertJsonPath('unread_notifications_count', 0)
        ->assertJsonPath('head.0.event_count', 1)
        ->assertJsonPath('head.0.actors_count', 1)
        ->assertJsonPath('head.0.unread_count', 0)
        ->assertJsonStructure([
            'head' => [['actor' => ['is_followed'], 'actors', 'commit']],
            'visible' => [['actor' => ['is_followed'], 'actors', 'commit']],
        ])
        ->assertJsonMissingPath('head.0.key')
        ->assertJsonMissingPath('head.0.group_rank')
        ->assertJsonMissingPath('head.0.actor_position')
        ->assertJsonMissingPath('head.0.sync_position')
        ->assertJsonMissingPath('visible.0.key')
        ->assertJsonMissingPath('visible.0.group_rank')
        ->assertJsonMissingPath('visible.0.actor_position')
        ->assertJsonMissingPath('visible.0.sync_position');

    expect(array_keys($response->json()))->toBe([
        'head',
        'boundary',
        'visible',
        'missing_visible_group_ids',
        'unread_notifications_count',
    ]);
});

it('rejects invalid sync input before acknowledging notifications', function (array $query, string $field): void {
    $user = User::factory()->create();
    $notification = Notification::factory()->for($user, 'recipient')->create();

    $this->actingAs($user)
        ->getJson(route('notification-sync.index', $query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect($notification->refresh()->read_at)->toBeNull();
})->with([
    'invalid filter' => fn (): array => [['type' => 'secret'], 'type'],
    'scalar visible value' => fn (): array => [['visible' => (string) Str::uuid()], 'visible'],
    'associative visible value' => fn (): array => [['visible' => ['group' => (string) Str::uuid()]], 'visible'],
    'invalid visible uuid' => fn (): array => [['visible' => ['invalid']], 'visible.0'],
    'duplicate visible uuid' => function (): array {
        $id = (string) Str::uuid();

        return [['visible' => [$id, $id]], 'visible.1'];
    },
    'twenty one visible uuids' => fn (): array => [[
        'visible' => collect(range(1, 21))->map(fn (): string => (string) Str::uuid())->all(),
    ], 'visible'],
]);

it('keeps global group identity while acknowledging all relevant activity', function (): void {
    $user = User::factory()->create();
    $root = Commit::factory()->for($user)->create();
    $oldReply = Commit::factory()->for($root, 'parent')->create();
    $old = Notification::factory()->for($user, 'recipient')->for($oldReply, 'commit')->create([
        'type' => NotificationType::Replied,
        'created_at' => now()->subMinutes(40),
    ]);
    $latestReply = Commit::factory()->for($root, 'parent')->create();
    $latest = Notification::factory()->for($user, 'recipient')->for($latestReply, 'commit')->create([
        'type' => NotificationType::Replied,
    ]);
    $follow = Notification::factory()->for($user, 'recipient')->create();
    $hidden = Notification::factory()->for($user, 'recipient')->replied()->create();
    $hidden->commit->update(['status' => CommitStatus::Pending]);

    $other = Notification::factory()->create();

    $this->actingAs($user)->getJson(route('notification-sync.index', [
        'type' => 'replies',
        'visible' => [$old->id, $hidden->id, $other->id],
    ]))->assertOk()
        ->assertJsonPath('head.0.id', $latest->id)
        ->assertJsonPath('head.0.group_id', $old->id)
        ->assertJsonPath('head.0.event_count', 2)
        ->assertJsonPath('visible', [])
        ->assertJsonPath('missing_visible_group_ids', [$hidden->id, $other->id]);

    expect($latest->refresh()->read_at)->not->toBeNull()
        ->and($follow->refresh()->read_at)->not->toBeNull()
        ->and($hidden->refresh()->read_at)->toBeNull()
        ->and($other->refresh()->read_at)->toBeNull();
});

it('serializes a requested non-head group with its actor stack and follow state', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();
    $stargazers = User::factory()->count(4)->create();
    $stars = $stargazers->map(fn (User $stargazer, int $index): Notification => Notification::factory()
        ->for($user, 'recipient')
        ->for($stargazer, 'actor')
        ->starred($commit)
        ->create(['created_at' => now()->subMinutes(80 - $index)]));
    $user->following()->attach($stargazers[3]);
    Notification::factory()->for($user, 'recipient')->count(21)->replied()->create([
        'created_at' => now()->subMinutes(30),
    ]);

    $this->actingAs($user)->getJson(route('notification-sync.index', [
        'visible' => [$stars[0]->id],
    ]))->assertOk()
        ->assertJsonCount(1, 'visible')
        ->assertJsonPath('visible.0.id', $stars[3]->id)
        ->assertJsonPath('visible.0.group_id', $stars[0]->id)
        ->assertJsonPath('visible.0.actor.id', $stargazers[3]->id)
        ->assertJsonPath('visible.0.actor.is_followed', true)
        ->assertJsonPath('visible.0.commit.id', $commit->id)
        ->assertJsonPath('visible.0.event_count', 4)
        ->assertJsonPath('visible.0.actors_count', 4)
        ->assertJsonPath('visible.0.unread_count', 0)
        ->assertJsonCount(3, 'visible.0.actors')
        ->assertJsonPath('visible.0.actors.0.id', $stargazers[3]->id)
        ->assertJsonMissingPath('visible.0.actor.email')
        ->assertJsonMissingPath('visible.0.actors.0.email')
        ->assertJsonMissingPath('visible.0.sync_position');
});

it('reports activity committed after acknowledgement as unread', function (): void {
    $user = User::factory()->create();
    Notification::factory()->for($user, 'recipient')->create();
    $inserted = false;
    /** @var Notification|null $late */
    $late = null;

    DB::listen(function (QueryExecuted $query) use ($user, &$inserted, &$late): void {
        if ($inserted || ! Str::startsWith(Str::lower($query->sql), 'update') || ! Str::contains($query->sql, 'notifications')) {
            return;
        }

        $inserted = true;
        $late = Notification::factory()->for($user, 'recipient')->create();
    });

    $this->actingAs($user)
        ->getJson(route('notification-sync.index'))
        ->assertOk()
        ->assertJsonPath('unread_notifications_count', 1);

    expect($late)->toBeInstanceOf(Notification::class);
    assert($late instanceof Notification);
    expect($late->refresh()->read_at)->toBeNull();
});
