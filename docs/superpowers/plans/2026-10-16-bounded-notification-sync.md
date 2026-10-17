# Bounded Notification Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace depth-dependent notification inbox polling with a fixed-size, authenticated sync response that safely reconciles the loaded list or requests a full refresh.

**Architecture:** Laravel exposes a private JSON endpoint backed by one bounded representative query while the normal inbox paginator returns exactly 20 rows. A pure TypeScript reconciler proves continuity with `(group_id, id)` representative pairs; the React page polls the endpoint every 30 seconds and applies only safe responses.

**Tech Stack:** PHP 8.5, Laravel 13, PostgreSQL/SQLite, Pest 5, React 19, Inertia 3, TypeScript 7, Node test runner, Bun, Wayfinder, Vite Plus.

**Spec:** [`docs/superpowers/specs/2026-10-16-bounded-notification-sync-design.md`](../specs/2026-10-16-bounded-notification-sync-design.md)

## Global Constraints

- Keep `GET /notifications/sync` inside the existing `auth` route group and return `Cache-Control: private, no-store`.
- Accept the existing `type` filter and at most 20 distinct UUID values in `visible[]`.
- Query 21 representatives, return 20 in `head`, and expose only `{ group_id, id }` for the 21st `boundary`.
- Select head, boundary, and requested visible representative identities in one SQL statement so PostgreSQL `READ COMMITTED` cannot mix snapshots.
- Return at most 20 head rows plus 20 non-head visible rows; loaded pagination depth must never alter either bound.
- Preserve existing `NotificationGroup` serialization, filtering, global group identity, stacked actors, follow state, and read acknowledgement.
- Compare both `group_id` and representative `id`; stable group identity alone is not continuity proof.
- Keep the deepest cursor for safe partial reconciliation and clear it only when `boundary` is `null`.
- Reconcile inside one functional Inertia page-prop replacement so a page merged by `InfiniteScroll` after render but before application cannot be overwritten by stale React props.
- When a complete response meets an active Inertia next-page token, use a server-backed page-one reset; replacing the paginator prop alone does not clear InfiniteScroll's separate cursor state.
- Poll every 30 seconds, pause in hidden tabs, prohibit overlap, abort navigation/unmount requests, and time out after 10 seconds.
- Keep ambiguous, offscreen, relevance-only, and delayed-transaction changes behind the explicit page-one refresh fallback described by the spec.
- Do not add a migration, dependency, persistent group projection, or full-history query rewrite; upstream PR #264 owns the latter optimization.
- Do not change `package.json` or test discovery; PR #300 separately owns React/TSX test discovery.
- Generated Wayfinder files under `resources/js/actions`, `resources/js/routes`, and `resources/js/wayfinder` remain ignored and uncommitted.
- Before implementation checks, provision the locked dependencies and PHP 8.5. The current host default is PHP 8.4 and this worktree has no `vendor` or `node_modules`; local PHP results under 8.4 are supplemental, and the PHP 8.5 CI gate is authoritative.

## Review Focus

- A boundary or prefix row with a stable `group_id` but changed representative `id` must not prove continuity; Task 4 pins this with prefix and mixed-burst regression tests.
- A requested visible UUID belonging to another recipient or an irrelevant row must return no model data; Tasks 1 and 2 pin this as a tombstone/privacy case.
- Invalid, duplicate, associative, or over-limit `visible` input must fail before acknowledgement; Task 2 verifies `read_at` remains null.
- Activity inserted after acknowledgement must remain unread in the returned global count; Task 2 inserts it from a guarded query listener.
- A requested tail group changed after representative selection must not leak a newer representative into an older head snapshot; Task 1 pins the single-statement boundary.
- A complete response (`boundary: null`) must remove stale retained history and clear the cursor; Task 4 tests both effects.
- A complete response with an active next-page token must reset Inertia's server scroll metadata before the list is replaced; Tasks 4 and 5 pin this path.
- A page appended while a poll response waits in Inertia's client queue must remain present with its newer deepest cursor; Tasks 4 and 5 require reconciliation against the updater's current props.

## File Map

- Create `app/Http/Controllers/NotificationSyncController.php`: thin JSON adapter for acknowledgement, query results, unread count, and cache headers.
- Create `app/Http/Requests/ListNotificationSyncRequest.php`: validates `type` and the bounded visible UUID list.
- Modify `app/Queries/NotificationGroupsQuery.php`: adds bounded synchronization, shared representative ordering, and batched relation loading; removes dynamic page expansion.
- Modify `app/Http/Controllers/NotificationController.php`: removes the retired `through` argument.
- Modify `app/Http/Requests/ListNotificationsRequest.php`: removes `through` validation and parsing.
- Modify `routes/web.php`: registers the authenticated sync route.
- Modify `tests/Unit/Queries/NotificationGroupsQueryTest.php`: proves bounded query shape, tombstones, serialization relations, and group bumps.
- Create `tests/Feature/Controllers/NotificationSyncControllerTest.php`: proves the HTTP, security, validation, caching, filtering, and acknowledgement contract.
- Modify `tests/Feature/NotificationInboxTest.php`: replaces obsolete expanding-poll assertions with fixed pagination, scroll-reset metadata, and stale-client reload coverage.
- Modify `resources/js/types/notification.ts`: defines the sync response contract.
- Create `resources/js/lib/notification-sync.ts`: owns visibility selection and pure reconciliation.
- Create `tests/Unit/NotificationSync.test.ts`: exercises every continuity and merge branch through the current `*.test.ts` runner.
- Modify `resources/js/pages/notification/index.tsx`: replaces Inertia reload polling with bounded JSON polling and the fallback control.

---

### Task 1: Build the bounded server query

**Files:**
- Modify: `app/Queries/NotificationGroupsQuery.php:16-164`
- Modify: `tests/Unit/Queries/NotificationGroupsQueryTest.php:1-247`

**Interfaces:**
- Consumes: existing `events(User $user, string $type): NotificationBuilder`, `PER_PAGE = 20`, and `loadStackedActors()` semantics.
- Produces: `sync(User $user, string $type = 'all', array $visibleGroupIds = []): array{head: list<Notification>, boundary: array{group_id: string, id: string}|null, visible: list<Notification>, missing_visible_group_ids: list<string>}`.
- Produces: private `groupRepresentatives(User $user, string $type): NotificationBuilder` and `loadGroupRelations(User $user, string $type, array $groups): void` for Task 3 reuse.
- Guarantees: one representative SELECT returns positions 1–21 plus requested visible group IDs; relation queries may follow, but representative identity cannot cross statement snapshots.

- [ ] **Step 1: Add the failing bounded-window query test**

Add `CommitStatus` and `QueryExecuted` to the imports and append this test:

```php
it('builds a bounded sync head with visible refreshes and ordered tombstones', function (): void {
    $user = User::factory()->create();
    $groups = collect(range(1, 62))->map(fn (int $minutes): Notification => Notification::factory()
        ->for($user, 'recipient')
        ->replied()
        ->create(['created_at' => now()->subMinutes($minutes)]));
    $visible = $groups[30];
    $hidden = $groups[40];
    $hidden->commit->update(['status' => CommitStatus::Pending]);
    $other = Notification::factory()->replied()->create();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $sync = resolve(NotificationGroupsQuery::class)->sync($user, visibleGroupIds: [
        $groups[0]->id,
        $groups[20]->id,
        $visible->id,
        $hidden->id,
        $other->id,
    ]);

    expect($sync['head'])->toHaveCount(NotificationGroupsQuery::PER_PAGE)
        ->and(array_map(fn (Notification $group): string => $group->id, $sync['head']))
        ->toBe($groups->take(20)->pluck('id')->all())
        ->and($sync['boundary'])->toBe([
            'group_id' => $groups[20]->id,
            'id' => $groups[20]->id,
        ])
        ->and(array_map(fn (Notification $group): string => $group->group_id, $sync['visible']))
        ->toBe([$groups[20]->id, $visible->id])
        ->and($sync['missing_visible_group_ids'])->toBe([$hidden->id, $other->id])
        ->and($sync['head'][0]->relationLoaded('actor'))->toBeTrue()
        ->and($sync['head'][0]->relationLoaded('commit'))->toBeTrue()
        ->and($sync['head'][0]->relationLoaded('actors'))->toBeTrue()
        ->and(DB::getQueryLog())->toHaveCount(3);
});
```

- [ ] **Step 2: Add failing completion and stable-group bump tests**

```php
it('uses a null sync boundary when the head contains the complete inbox', function (int $count): void {
    $user = User::factory()->create();
    Notification::factory()->for($user, 'recipient')->count($count)->replied()->create();

    $sync = resolve(NotificationGroupsQuery::class)->sync($user);

    expect($sync['head'])->toHaveCount($count)
        ->and($sync['boundary'])->toBeNull()
        ->and($sync['visible'])->toBe([])
        ->and($sync['missing_visible_group_ids'])->toBe([]);
})->with([0, 1, NotificationGroupsQuery::PER_PAGE]);

it('moves an active old group into the sync head without changing its group id', function (): void {
    $user = User::factory()->create();
    $root = Commit::factory()->for($user)->create();
    $oldReply = Commit::factory()->for($root, 'parent')->create();
    $old = Notification::factory()->for($user, 'recipient')->for($oldReply, 'commit')->create([
        'type' => NotificationType::Replied,
        'created_at' => now()->subHour(),
    ]);
    Notification::factory()->for($user, 'recipient')->count(20)->replied()->create([
        'created_at' => now()->subMinutes(30),
    ]);
    $latestReply = Commit::factory()->for($root, 'parent')->create();
    $latest = Notification::factory()->for($user, 'recipient')->for($latestReply, 'commit')->create([
        'type' => NotificationType::Replied,
    ]);

    $sync = resolve(NotificationGroupsQuery::class)->sync($user);

    expect($sync['head'][0]->id)->toBe($latest->id)
        ->and($sync['head'][0]->group_id)->toBe($old->id)
        ->and($sync['head'][0]->event_count)->toBe(2);
});

it('selects head boundary and visible representatives from one statement snapshot', function (): void {
    $user = User::factory()->create();
    $root = Commit::factory()->for($user)->create();
    $oldReply = Commit::factory()->for($root, 'parent')->create();
    $old = Notification::factory()->for($user, 'recipient')->for($oldReply, 'commit')->create([
        'type' => NotificationType::Replied,
        'created_at' => now()->subMinutes(50),
        'read_at' => now(),
    ]);
    Notification::factory()->for($user, 'recipient')->count(21)->replied()->read()->create([
        'created_at' => now()->subMinutes(30),
    ]);
    $inserted = false;
    /** @var Notification|null $late */
    $late = null;

    DB::listen(function (QueryExecuted $event) use ($user, $root, &$inserted, &$late): void {
        if ($inserted || ! str_starts_with(strtolower(ltrim($event->sql)), 'select')) {
            return;
        }

        $inserted = true;
        $reply = Commit::factory()->for($root, 'parent')->create();
        $late = Notification::factory()->for($user, 'recipient')->for($reply, 'commit')->create([
            'type' => NotificationType::Replied,
        ]);
    });

    $query = resolve(NotificationGroupsQuery::class);
    $sync = $query->sync($user, visibleGroupIds: [$old->id]);

    expect($late)->toBeInstanceOf(Notification::class);
    assert($late instanceof Notification);
    expect($sync['visible'])->toHaveCount(1)
        ->and($sync['visible'][0]->id)->toBe($old->id)
        ->and(collect($sync['head'])->pluck('id'))->not->toContain($late->id)
        ->and($query->unreadCount($user))->toBe(1);
});
```

- [ ] **Step 3: Run the focused query tests and confirm the new contract is absent**

Run:

```bash
php -d memory_limit=-1 vendor/bin/pest tests/Unit/Queries/NotificationGroupsQueryTest.php
```

Expected: FAIL because `NotificationGroupsQuery::sync()` is undefined.

- [ ] **Step 4: Add the representative and relation-loading seams**

Refactor `paginate()` to use these private methods without changing its current behavior yet:

```php
private function groupRepresentatives(User $user, string $type): NotificationBuilder
{
    return $this->events($user, $type)
        ->where('group_rank', 1)
        ->latest()
        ->latest('id');
}

/** @param list<Notification> $groups */
private function loadGroupRelations(User $user, string $type, array $groups): void
{
    (new Collection($groups))->load([
        'actor' => fn (BelongsTo $query): BelongsTo => $query
            ->withExists(['followers as is_followed' => fn (Builder $query): Builder => $query->whereKey($user->id)]),
        'commit',
    ]);

    $this->loadStackedActors($user, $type, $groups);
}
```

- [ ] **Step 5: Implement the bounded synchronization method**

```php
/**
 * @param  list<string>  $visibleGroupIds
 * @return array{
 *     head: list<Notification>,
 *     boundary: array{group_id: string, id: string}|null,
 *     visible: list<Notification>,
 *     missing_visible_group_ids: list<string>
 * }
 */
public function sync(User $user, string $type = 'all', array $visibleGroupIds = []): array
{
    $visibleGroupIds = array_slice($visibleGroupIds, 0, self::PER_PAGE);
    $ranked = $this->groupRepresentatives($user, $type)
        ->reorder()
        ->select('notifications.*')
        ->selectRaw('ROW_NUMBER() OVER (ORDER BY created_at DESC, id DESC) AS sync_position');
    $groups = Notification::query()
        ->fromSub($ranked, 'notifications')
        ->where(fn (NotificationBuilder $query): NotificationBuilder => $query
            ->where('sync_position', '<=', self::PER_PAGE + 1)
            ->when(
                $visibleGroupIds !== [],
                fn (NotificationBuilder $query): NotificationBuilder => $query
                    ->orWhereIn('group_id', $visibleGroupIds),
            ))
        ->withCasts([
            'event_count' => 'integer',
            'sync_position' => 'integer',
        ])
        ->orderBy('sync_position')
        ->get();
    $headWithBoundary = $groups->take(self::PER_PAGE + 1)->values();
    $boundary = $headWithBoundary->count() > self::PER_PAGE
        ? $headWithBoundary->pop()
        : null;
    $head = $headWithBoundary->values()->all();
    $headIds = collect($head)->pluck('group_id');
    $requestedVisibleIds = collect($visibleGroupIds)->diff($headIds)->values();
    $visible = $groups->whereIn('group_id', $requestedVisibleIds)->values()->all();

    $this->loadGroupRelations($user, $type, [...$head, ...$visible]);

    foreach ([...$head, ...$visible] as $group) {
        $group->makeHidden('sync_position');
    }

    $foundIds = $headIds->merge(array_map(
        fn (Notification $group): string => $group->group_id,
        $visible,
    ));

    return [
        'head' => $head,
        'boundary' => $boundary instanceof Notification ? [
            'group_id' => $boundary->group_id,
            'id' => $boundary->id,
        ] : null,
        'visible' => $visible,
        'missing_visible_group_ids' => collect($visibleGroupIds)
            ->diff($foundIds)
            ->values()
            ->all(),
    ];
}
```

- [ ] **Step 6: Run the query suite and structural check**

Run:

```bash
php -d memory_limit=-1 vendor/bin/pest tests/Unit/Queries/NotificationGroupsQueryTest.php
/home/shawn/.local/bin/ripwire . --edit-check=NotificationGroupsQuery
```

Expected: all query tests pass, including the existing constant-query-count cases; Ripwire reports no blocking regression.

- [ ] **Step 7: Commit the bounded query**

```bash
git add app/Queries/NotificationGroupsQuery.php tests/Unit/Queries/NotificationGroupsQueryTest.php
git commit -m "feat: add bounded notification sync query"
```

---

### Task 2: Expose the authenticated sync contract

**Files:**
- Create: `app/Http/Requests/ListNotificationSyncRequest.php`
- Create: `app/Http/Controllers/NotificationSyncController.php`
- Create: `tests/Feature/Controllers/NotificationSyncControllerTest.php`
- Modify: `routes/web.php:5-55,215-217`

**Interfaces:**
- Consumes: Task 1 `NotificationGroupsQuery::sync()` and existing `UpdateNotificationsReadAt::handle(User): CarbonImmutable`.
- Produces: Wayfinder action `NotificationSyncController.index()` and route name `notification-sync.index`.
- Produces JSON keys: `head`, `boundary`, `visible`, `missing_visible_group_ids`, and `unread_notifications_count`.

- [ ] **Step 1: Add failing authentication, validation, and payload-bound tests**

Create the controller test with these imports and cases:

```php
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
        ->assertExactJsonPath('boundary', [
            'group_id' => $groups[20]->id,
            'id' => $groups[20]->id,
        ])
        ->assertJsonCount(20, 'visible')
        ->assertExactJsonPath('missing_visible_group_ids', [])
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
```

- [ ] **Step 2: Add failing filter, privacy, acknowledgement, and race tests**

Append:

```php
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
        ->assertExactJsonPath('visible', [])
        ->assertExactJsonPath('missing_visible_group_ids', [$hidden->id, $other->id]);

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
```

- [ ] **Step 3: Run the controller test and verify it fails at the missing route**

Run:

```bash
php -d memory_limit=-1 vendor/bin/pest tests/Feature/Controllers/NotificationSyncControllerTest.php
```

Expected: FAIL because `notification-sync.index` is undefined.

- [ ] **Step 4: Implement the dedicated Form Request**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListNotificationSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'in:all,replies,quotes,stars,mentions,follows,moderation'],
            'visible' => ['sometimes', 'array', 'list', 'max:20'],
            'visible.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    public function type(): string
    {
        return $this->filled('type') ? $this->string('type')->value() : 'all';
    }

    /** @return list<string> */
    public function visible(): array
    {
        /** @var list<string> $visible */
        $visible = $this->validated('visible', []);

        return $visible;
    }
}
```

- [ ] **Step 5: Implement the thin controller and authenticated route**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateNotificationsReadAt;
use App\Http\Requests\ListNotificationSyncRequest;
use App\Models\User;
use App\Queries\NotificationGroupsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final readonly class NotificationSyncController
{
    public function index(
        ListNotificationSyncRequest $request,
        #[CurrentUser] User $user,
        NotificationGroupsQuery $query,
        UpdateNotificationsReadAt $action,
    ): JsonResponse {
        $action->handle($user);

        return response()->json([
            ...$query->sync($user, $request->type(), $request->visible()),
            'unread_notifications_count' => $query->unreadCount($user),
        ])->header('Cache-Control', 'private, no-store');
    }
}
```

Add the controller import beside the existing notification controller imports:

```php
use App\Http\Controllers\NotificationSyncController;
```

Then register the route inside the existing authenticated group, before the group-history route:

```php
Route::get('notifications/sync', [NotificationSyncController::class, 'index'])
    ->name('notification-sync.index');
Route::get('notifications', [NotificationController::class, 'index'])
    ->name('notification.index');
Route::get('notifications/{notification}/group', [NotificationGroupController::class, 'show'])
    ->name('notification-group.show');
```

- [ ] **Step 6: Run endpoint tests, route inspection, and Wayfinder generation**

Run:

```bash
php -d memory_limit=-1 vendor/bin/pest tests/Feature/Controllers/NotificationSyncControllerTest.php
php artisan route:list --name=notification-sync
php artisan wayfinder:generate --with-form
git status --short
```

Expected: controller tests pass; route output contains authenticated `notifications/sync`; generated ignored files do not appear in Git status.

- [ ] **Step 7: Commit the HTTP contract**

```bash
git add app/Http/Controllers/NotificationSyncController.php app/Http/Requests/ListNotificationSyncRequest.php routes/web.php tests/Feature/Controllers/NotificationSyncControllerTest.php
git commit -m "feat: expose bounded notification sync"
```

---

### Task 3: Retire depth-dependent inbox pagination

**Files:**
- Modify: `app/Http/Requests/ListNotificationsRequest.php:7-34`
- Modify: `app/Http/Controllers/NotificationController.php:18-25`
- Modify: `app/Queries/NotificationGroupsQuery.php:21-39`
- Modify: `tests/Feature/NotificationInboxTest.php:33-104`

**Interfaces:**
- Consumes: Task 1 `groupRepresentatives()` and `loadGroupRelations()`.
- Produces: `paginate(User $user, string $type = 'all'): CursorPaginator`, always using `PER_PAGE` and preserving only the `type` query parameter.

- [ ] **Step 1: Replace expanding-poll tests with fixed-page, scroll-reset, and stale-client tests**

Delete the three obsolete polling tests at the top of `NotificationInboxTest.php` and add:

```php
it('keeps a legacy inbox refresh query to one fixed page', function (): void {
    $user = User::factory()->create();
    $notices = collect(range(1, 62))->map(fn (int $minutes): Notification => Notification::factory()
        ->for($user, 'recipient')
        ->replied()
        ->create(['created_at' => now()->subMinutes($minutes)]));

    $this->actingAs($user)
        ->get(route('notification.index', ['through' => $notices[39]->created_at->toJSON()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', NotificationGroupsQuery::PER_PAGE)
            ->where('notifications.data.0.id', $notices[0]->id)
            ->where('notifications.next_page_url', fn (string $url): bool => ! str_contains($url, 'through')));
});

it('marks an explicit page-one inbox visit as an infinite-scroll reset', function (): void {
    $user = User::factory()->create();
    Notification::factory()->for($user, 'recipient')->count(21)->replied()->create();

    $this->actingAs($user)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request()),
        'X-Inertia-Partial-Component' => 'notification/index',
        'X-Inertia-Partial-Data' => 'notifications,filters,readAt,auth',
        'X-Inertia-Reset' => 'notifications',
    ])->get(route('notification.index'))->assertOk()
        ->assertJsonCount(NotificationGroupsQuery::PER_PAGE, 'props.notifications.data')
        ->assertJsonPath('scrollProps.notifications.reset', true);
});

it('forces a stale notification client to reload before it can reuse the retired poll contract', function (): void {
    $user = User::factory()->create();
    $notification = Notification::factory()->for($user, 'recipient')->create();
    $url = route('notification.index', ['through' => $notification->created_at->toJSON()]);

    $this->actingAs($user)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => 'stale-notification-client',
    ])->get($url)
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', $url);
});
```

- [ ] **Step 2: Run the inbox test and confirm the fixed-page assertion fails**

Run:

```bash
php -d memory_limit=-1 vendor/bin/pest tests/Feature/NotificationInboxTest.php
```

Expected: FAIL because the current `through` branch expands the first response to 40 rows.

- [ ] **Step 3: Remove `through` from request parsing and controller dispatch**

Make `ListNotificationsRequest::rules()` return only the `type` rule, delete the `CarbonImmutable` import and `through()` method, and change the controller callback to:

```php
'notifications' => Inertia::scroll(
    fn (): CursorPaginator => $query->paginate($user, $request->type()),
)->matchOn('data.group_id'),
```

- [ ] **Step 4: Make pagination fixed and retain only the filter query parameter**

Replace `paginate()` with:

```php
/** @return CursorPaginator<int, Notification> */
public function paginate(User $user, string $type = 'all'): CursorPaginator
{
    $groups = $this->groupRepresentatives($user, $type)
        ->cursorPaginate(self::PER_PAGE)
        ->appends(request()->only('type'));

    $this->loadGroupRelations($user, $type, $groups->items());

    return $groups;
}
```

- [ ] **Step 5: Run all server-focused tests and static checks**

Run:

```bash
php -d memory_limit=-1 vendor/bin/pest tests/Unit/Queries/NotificationGroupsQueryTest.php tests/Feature/Controllers/NotificationSyncControllerTest.php tests/Feature/NotificationInboxTest.php
composer test:types
composer test:type-coverage
```

Expected: all focused tests, PHPStan, and 100% type coverage pass.

- [ ] **Step 6: Commit the retired poll contract**

```bash
git add app/Http/Controllers/NotificationController.php app/Http/Requests/ListNotificationsRequest.php app/Queries/NotificationGroupsQuery.php tests/Feature/NotificationInboxTest.php
git commit -m "fix: keep notification pagination bounded"
```

---

### Task 4: Implement and test pure client reconciliation

**Files:**
- Modify: `resources/js/types/notification.ts:4-42`
- Create: `resources/js/lib/notification-sync.ts`
- Create: `tests/Unit/NotificationSync.test.ts`

**Interfaces:**
- Consumes: existing `CursorPaginated<NotificationGroup>` and server keys from Task 2.
- Produces: `NotificationSyncResponse` and `NotificationSyncRepresentative` types.
- Produces: `reconcileNotificationSync(current, sync): { notifications, refreshRequired, paginationResetRequired }`.
- Produces: `visibleNotificationGroupIds(elements, headerBottom, viewport): string[]`, capped at 20 in DOM order.

- [ ] **Step 1: Add the failing reconciliation suite**

Create `tests/Unit/NotificationSync.test.ts`:

```ts
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    reconcileNotificationSync,
    visibleNotificationGroupIds,
} from '../../resources/js/lib/notification-sync.ts';
import type {
    CursorPaginated,
    NotificationGroup,
    NotificationSyncResponse,
} from '../../resources/js/types/index.ts';

const group = (groupId: string, id = groupId): NotificationGroup =>
    ({ group_id: groupId, id }) as NotificationGroup;

const sequence = (prefix: string, count: number): NotificationGroup[] =>
    Array.from({ length: count }, (_, index) => group(`${prefix}${index + 1}`));

const page = (
    data: NotificationGroup[],
    nextCursor: string | null = 'deep-cursor',
): CursorPaginated<NotificationGroup> => ({
    data,
    next_cursor: nextCursor,
    prev_cursor: null,
});

const response = (
    values: Partial<NotificationSyncResponse>,
): NotificationSyncResponse => ({
    head: [],
    boundary: null,
    visible: [],
    missing_visible_group_ids: [],
    unread_notifications_count: 0,
    ...values,
});

void test('replaces a twenty-row head and retains the loaded tail and cursor', () => {
    const loaded = sequence('A', 59);
    const head = [group('X'), ...loaded.slice(0, 19)];
    const result = reconcileNotificationSync(
        page(loaded),
        response({ head, boundary: group('A20') }),
    );

    assert.equal(result.refreshRequired, false);
    assert.deepEqual(
        result.notifications.data.map(({ group_id }) => group_id),
        ['X', ...loaded.map(({ group_id }) => group_id)],
    );
    assert.equal(result.notifications.data.length, 60);
    assert.equal(result.notifications.next_cursor, 'deep-cursor');
});

void test('preserves a page merged after the poll request started', () => {
    const atRequestStart = sequence('A', 20);
    const currentAtApply = page(
        [...atRequestStart, ...sequence('merged-', 20)],
        'new-deep-cursor',
    );
    const head = [group('X'), ...atRequestStart.slice(0, 19)];
    const result = reconcileNotificationSync(
        currentAtApply,
        response({ head, boundary: group('A20') }),
    );

    assert.equal(result.refreshRequired, false);
    assert.equal(result.paginationResetRequired, false);
    assert.equal(result.notifications.data.length, 41);
    assert.equal(result.notifications.data.at(-1)?.group_id, 'merged-20');
    assert.equal(result.notifications.next_cursor, 'new-deep-cursor');
});

void test('deduplicates a bumped old group by stable group id', () => {
    const loaded = sequence('A', 30);
    const bumped = group('A30', 'A30:new');
    const result = reconcileNotificationSync(
        page(loaded),
        response({
            head: [bumped, ...loaded.slice(0, 19)],
            boundary: group('A20'),
        }),
    );

    assert.equal(result.refreshRequired, false);
    assert.equal(result.notifications.data[0], bumped);
    assert.equal(
        result.notifications.data.filter(({ group_id }) => group_id === 'A30')
            .length,
        1,
    );
});

void test('applies visible replacements and missing-row tombstones', () => {
    const head = sequence('A', 20);
    const current = page([...head, group('A21'), group('visible'), group('missing')]);
    const replacement = group('visible', 'visible:new');
    const result = reconcileNotificationSync(
        current,
        response({
            head,
            boundary: group('A21'),
            visible: [replacement],
            missing_visible_group_ids: ['missing'],
        }),
    );

    assert.equal(result.refreshRequired, false);
    assert.equal(
        result.notifications.data.find(({ group_id }) => group_id === 'visible'),
        replacement,
    );
    assert.equal(
        result.notifications.data.some(({ group_id }) => group_id === 'missing'),
        false,
    );
});

void test('treats a null boundary as complete and clears retained history and cursor', () => {
    const head = sequence('A', 4);
    const result = reconcileNotificationSync(
        page([...head, ...sequence('stale', 5)]),
        response({ head }),
    );

    assert.equal(result.refreshRequired, false);
    assert.equal(result.paginationResetRequired, true);
    assert.deepEqual(result.notifications.data, head);
    assert.equal(result.notifications.next_cursor, null);
});

void test('updates a complete paginator locally when its cursor is already exhausted', () => {
    const head = sequence('A', 4);
    const result = reconcileNotificationSync(
        page([...head, ...sequence('stale', 5)], null),
        response({ head }),
    );

    assert.equal(result.refreshRequired, false);
    assert.equal(result.paginationResetRequired, false);
    assert.deepEqual(result.notifications.data, head);
    assert.equal(result.notifications.next_cursor, null);
});

void test('accepts an unchanged representative prefix with an unloaded boundary', () => {
    const head = sequence('A', 20);
    const result = reconcileNotificationSync(
        page(head),
        response({ head: [...head], boundary: group('A21') }),
    );

    assert.equal(result.refreshRequired, false);
    assert.equal(result.notifications.next_cursor, 'deep-cursor');
});

void test('rejects a matching group-id prefix when a representative id changed', () => {
    const loaded = sequence('A', 20);
    const changed = [group('A1', 'A1:new'), ...loaded.slice(1)];
    const result = reconcileNotificationSync(
        page(loaded),
        response({ head: changed, boundary: group('A21') }),
    );

    assert.equal(result.refreshRequired, true);
    assert.equal(result.notifications.data, loaded);
});

void test('rejects a mixed burst whose stable boundary group has a new representative', () => {
    const current = page(sequence('A', 40));
    const burst = Array.from({ length: 20 }, (_, index) => group(`X${21 - index}`));
    const result = reconcileNotificationSync(
        current,
        response({
            head: burst,
            boundary: group('A30', 'A30:new'),
        }),
    );

    assert.equal(result.refreshRequired, true);
    assert.equal(result.notifications, current);
});

void test('returns the original paginator when no continuity proof exists', () => {
    const current = page(sequence('A', 20));
    const result = reconcileNotificationSync(
        current,
        response({ head: sequence('X', 20), boundary: group('unknown') }),
    );

    assert.equal(result.refreshRequired, true);
    assert.equal(result.notifications, current);
});

void test('collects distinct visible rows below the header and stops at twenty', () => {
    const element = (
        id: string,
        bounds: Partial<DOMRect> = {},
    ): HTMLElement =>
        ({
            dataset: { notificationGroup: id },
            getBoundingClientRect: () => ({
                width: 10,
                height: 10,
                top: 30,
                bottom: 40,
                left: 0,
                right: 10,
                ...bounds,
            }),
        }) as unknown as HTMLElement;
    const visible = Array.from({ length: 22 }, (_, index) =>
        element(`visible-${index + 1}`),
    );

    const ids = visibleNotificationGroupIds(
        [
            element('zero-size', { width: 0 }),
            element('under-header', { top: 0, bottom: 10 }),
            element('offscreen', { top: 110, bottom: 120 }),
            visible[0],
            ...visible,
        ],
        20,
        { innerHeight: 100, innerWidth: 100 },
    );

    assert.deepEqual(
        ids,
        Array.from({ length: 20 }, (_, index) => `visible-${index + 1}`),
    );
});
```

- [ ] **Step 2: Run the direct unit test and confirm the module is missing**

Run:

```bash
node --experimental-strip-types --test tests/Unit/NotificationSync.test.ts
```

Expected: FAIL because `resources/js/lib/notification-sync.ts` does not exist.

- [ ] **Step 3: Add the shared response types**

Append to `resources/js/types/notification.ts`:

```ts
export type NotificationSyncRepresentative = Pick<
    NotificationGroup,
    'group_id' | 'id'
>;

export type NotificationSyncResponse = {
    head: NotificationGroup[];
    boundary: NotificationSyncRepresentative | null;
    visible: NotificationGroup[];
    missing_visible_group_ids: string[];
    unread_notifications_count: number;
};
```

- [ ] **Step 4: Implement visibility selection and reconciliation**

Create `resources/js/lib/notification-sync.ts`:

```ts
import type {
    CursorPaginated,
    NotificationGroup,
    NotificationSyncRepresentative,
    NotificationSyncResponse,
} from '@/types';

const maxVisibleGroups = 20;

const sameRepresentative = (
    left: NotificationSyncRepresentative,
    right: NotificationSyncRepresentative,
): boolean => left.group_id === right.group_id && left.id === right.id;

const uniqueGroups = (groups: NotificationGroup[]): NotificationGroup[] => {
    const seen = new Set<string>();

    return groups.filter(({ group_id }) => {
        if (seen.has(group_id)) {
            return false;
        }

        seen.add(group_id);

        return true;
    });
};

export function visibleNotificationGroupIds(
    elements: Iterable<HTMLElement>,
    headerBottom: number,
    viewport: Pick<Window, 'innerHeight' | 'innerWidth'>,
): string[] {
    const ids: string[] = [];
    const seen = new Set<string>();

    for (const element of elements) {
        const id = element.dataset.notificationGroup ?? '';
        const bounds = element.getBoundingClientRect();
        const visible =
            id !== '' &&
            bounds.width > 0 &&
            bounds.height > 0 &&
            bounds.bottom > headerBottom &&
            bounds.top < viewport.innerHeight &&
            bounds.right > 0 &&
            bounds.left < viewport.innerWidth;

        if (!visible || seen.has(id)) {
            continue;
        }

        seen.add(id);
        ids.push(id);

        if (ids.length === maxVisibleGroups) {
            break;
        }
    }

    return ids;
}

export function reconcileNotificationSync(
    current: CursorPaginated<NotificationGroup>,
    sync: NotificationSyncResponse,
): {
    notifications: CursorPaginated<NotificationGroup>;
    refreshRequired: boolean;
    paginationResetRequired: boolean;
} {
    if (sync.boundary === null) {
        return {
            notifications: {
                ...current,
                data: uniqueGroups(sync.head),
                next_cursor: null,
            },
            refreshRequired: false,
            paginationResetRequired: current.next_cursor !== null,
        };
    }

    const knownBoundary = current.data.some((group) =>
        sameRepresentative(group, sync.boundary as NotificationSyncRepresentative),
    );
    const unchangedPrefix =
        sync.head.length > 0 &&
        sync.head.every((group, index) => {
            const loaded = current.data[index];

            return loaded !== undefined && sameRepresentative(group, loaded);
        });

    if (!knownBoundary && !unchangedPrefix) {
        return {
            notifications: current,
            refreshRequired: true,
            paginationResetRequired: false,
        };
    }

    const headIds = new Set(sync.head.map(({ group_id }) => group_id));
    const missingIds = new Set(sync.missing_visible_group_ids);
    const visible = new Map(
        sync.visible.map((group) => [group.group_id, group]),
    );
    const retained = current.data
        .filter(
            ({ group_id }) =>
                !headIds.has(group_id) && !missingIds.has(group_id),
        )
        .map((group) => visible.get(group.group_id) ?? group);

    return {
        notifications: {
            ...current,
            data: uniqueGroups([...sync.head, ...retained]),
        },
        refreshRequired: false,
        paginationResetRequired: false,
    };
}
```

`paginationResetRequired` is separate from `refreshRequired`: a null boundary proves the response is complete, but Inertia's `InfiniteScroll` data manager keeps its own next-page token. The page must perform a server-backed reset when that token was active instead of applying the otherwise-safe paginator locally.

The `current` argument must be the paginator from Inertia's page at client-visit execution time, not the `notifications` prop captured when React last rendered. Task 5 supplies it from a functional `router.replace()` callback so an intervening infinite-scroll merge and its deeper cursor survive.

- [ ] **Step 5: Run direct and complete client unit suites plus type checking**

Run:

```bash
node --experimental-strip-types --test tests/Unit/NotificationSync.test.ts
bun run test:unit
bun run test:types
```

Expected: the new suite, every directly discovered TypeScript test, application types, and test types pass.

- [ ] **Step 6: Commit the pure client contract**

```bash
git add resources/js/lib/notification-sync.ts resources/js/types/notification.ts tests/Unit/NotificationSync.test.ts
git commit -m "feat: reconcile bounded notification updates"
```

---

### Task 5: Wire bounded polling and the safe refresh fallback

**Files:**
- Modify: `resources/js/pages/notification/index.tsx:1-325`

**Interfaces:**
- Consumes: Task 2 Wayfinder action `NotificationSyncController.index`, Task 4 response type, visibility selector, and reconciler.
- Preserves: `firstVisibleGroup()`, the existing layout-effect scroll correction, numeric arrival calculation, filters, infinite scrolling, and `data-notification-group` markers.
- Produces: one functional `router.replace()` update that reconciles the paginator from current Inertia props and updates unread state atomically.
- Produces: sticky `refreshRequired` UI state and automatic complete-list recovery, both cleared only by successful page-one navigation.

- [ ] **Step 1: Replace polling imports and state**

Remove `CancelToken` and `usePoll`. Add `useEffectEvent`, alias the new Wayfinder action, and import the Task 4 contract:

```ts
import { Head, InfiniteScroll, Link, router } from '@inertiajs/react';
import {
    Fragment,
    useEffect,
    useEffectEvent,
    useId,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { index as sync } from '@/actions/App/Http/Controllers/NotificationSyncController';
import {
    reconcileNotificationSync,
    visibleNotificationGroupIds,
} from '@/lib/notification-sync';
import type {
    Auth,
    CursorPaginated,
    Notification,
    NotificationGroup,
    NotificationSyncResponse,
} from '@/types';
```

Add this page-prop shape next to `Filters` so the functional Inertia update is type-safe:

```ts
type NotificationPageProps = {
    auth: Auth;
    notifications: CursorPaginated<NotificationGroup>;
    [key: string]: unknown;
};
```

Replace the old cancel token and `usePoll` state with the following block, moving the existing `filterVisit` declaration here so it is defined exactly once:

```ts
const pollRequest = useRef<AbortController | null>(null);
const pollPaused = useRef(false);
const syncGeneration = useRef(0);
const filterNav = useRef<HTMLElement>(null);
const header = useRef<HTMLDivElement>(null);
const pollAnchor = useRef<PollAnchor | null>(null);
const filterVisit = useRef(0);
const [arrivals, setArrivals] = useState(0);
const [refreshRequired, setRefreshRequired] = useState(false);
```

- [ ] **Step 2: Add page-one reset, latest-prop application, and bounded interval polling**

Insert after the state declarations and before `filterLink`:

```ts
const refreshInbox = () => {
    const href = index({
        query: filters.type === 'all' ? {} : { type: filters.type },
    });
    const visit = ++filterVisit.current;
    syncGeneration.current++;
    pollPaused.current = true;
    pollRequest.current?.abort();
    pollAnchor.current = null;
    const finishRefresh = () => {
        if (visit === filterVisit.current) {
            setRefreshRequired(false);
            setArrivals(0);
            window.scrollTo({ top: 0 });
        }
    };

    router.visit(href, {
        only: ['notifications', 'filters', 'readAt', 'auth'],
        reset: ['notifications'],
        preserveState: true,
        onError: finishRefresh,
        onSuccess: finishRefresh,
        onFinish: () => {
            if (visit === filterVisit.current) {
                pollPaused.current = false;
            }
        },
    });
};

const applySync = useEffectEvent(
    (response: NotificationSyncResponse, generation: number) => {
        let reconciliation: ReturnType<
            typeof reconcileNotificationSync
        > | null = null;
        const finishReconciliation = () => {
            const result = reconciliation;

            if (generation !== syncGeneration.current || result === null) {
                return;
            }

            if (result.refreshRequired) {
                setRefreshRequired(true);

                return;
            }

            if (result.paginationResetRequired) {
                refreshInbox();
            }
        };

        router.replace<NotificationPageProps>({
            preserveScroll: true,
            preserveState: true,
            props: (currentProps) => {
                if (generation !== syncGeneration.current) {
                    return currentProps;
                }

                const auth = {
                    ...currentProps.auth,
                    unreadNotificationsCount:
                        response.unread_notifications_count,
                };

                if (refreshRequired) {
                    return { ...currentProps, auth };
                }

                const result = reconcileNotificationSync(
                    currentProps.notifications,
                    response,
                );
                reconciliation = result;

                if (
                    result.refreshRequired ||
                    result.paginationResetRequired
                ) {
                    return { ...currentProps, auth };
                }

                pollAnchor.current = firstVisibleGroup(
                    header.current,
                    currentProps.notifications.data,
                );

                return {
                    ...currentProps,
                    auth,
                    notifications: result.notifications,
                };
            },
            onError: finishReconciliation,
            onSuccess: finishReconciliation,
        });
    },
);

useEffect(() => {
    const lifecycle = new AbortController();
    let running = false;

    const poll = async () => {
        if (document.hidden || running || pollPaused.current) {
            return;
        }

        const generation = syncGeneration.current;
        const request = new AbortController();
        const visible = visibleNotificationGroupIds(
            document.querySelectorAll<HTMLElement>(
                '[data-notification-group]',
            ),
            header.current?.getBoundingClientRect().bottom ?? 0,
            window,
        );
        const query = {
            visible,
            ...(filters.type === 'all' ? {} : { type: filters.type }),
        };
        running = true;
        pollRequest.current = request;

        try {
            const result = await fetch(sync.url({ query }), {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                signal: AbortSignal.any([
                    lifecycle.signal,
                    request.signal,
                    AbortSignal.timeout(10_000),
                ]),
            });

            if (!result.ok) {
                return;
            }

            const response = (await result.json()) as NotificationSyncResponse;

            if (
                !lifecycle.signal.aborted &&
                !request.signal.aborted &&
                !pollPaused.current &&
                generation === syncGeneration.current
            ) {
                applySync(response, generation);
            }
        } catch {
            // Keep the last good inbox until the next interval.
        } finally {
            if (pollRequest.current === request) {
                pollRequest.current = null;
            }

            running = false;
        }
    };

    const interval = window.setInterval(() => void poll(), 30_000);

    return () => {
        syncGeneration.current++;
        window.clearInterval(interval);
        lifecycle.abort();
        pollRequest.current?.abort();
    };
}, [filters.type]);
```

Keep each successful-swap completion handler on both `onError` and `onSuccess`, including `finishReconciliation`, `finishRefresh`, and `finishNavigation`. Inertia chooses `onError` instead of `onSuccess` when the resulting page has non-empty error props, even though the replacement or GET swap completed; either branch must still surface ambiguity, launch the required reset, or clear sticky fallback state.

- [ ] **Step 3: Preserve cancellation across filter navigation**

At the start of the existing unmodified-click path, invalidate queued sync applications, pause, and abort; clear fallback state only on successful navigation and resume the interval on finish:

```ts
event.preventDefault();
const visit = ++filterVisit.current;
syncGeneration.current++;
pollPaused.current = true;
pollRequest.current?.abort();
pollAnchor.current = null;
setArrivals(0);
const finishNavigation = () => {
    if (visit === filterVisit.current) {
        setRefreshRequired(false);
    }
};
router.visit(href, {
    only: ['notifications', 'filters', 'auth'],
    reset: ['notifications'],
    preserveState: true,
    viewTransition: true,
    onError: finishNavigation,
    onSuccess: finishNavigation,
    onFinish: () => {
        if (visit === filterVisit.current) {
            pollPaused.current = false;
        }
    },
});
```

- [ ] **Step 4: Add the page-one recovery control for ambiguous responses**

Use the `refreshInbox` handler from Step 2 for ambiguous responses. Replace the arrival-control condition and click/text branches with:

```tsx
{(refreshRequired || arrivals > 0) && (
    <div className="pointer-events-none absolute inset-x-0 top-full flex justify-center pt-3">
        <button
            type="button"
            onClick={
                refreshRequired
                    ? refreshInbox
                    : () => {
                          setArrivals(0);
                          window.scrollTo({ top: 0, behavior: 'smooth' });
                      }
            }
            className="pointer-events-auto flex items-center gap-1.5 rounded-full bg-primary px-4 py-1.5 text-sm font-medium text-primary-foreground shadow-[0_4px_14px_-4px_rgb(0_0_0/0.35)] transition-colors hover:bg-primary-hover motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-top-2"
        >
            <ArrowUp aria-hidden className="size-4" />
            {refreshRequired ? (
                'New notifications'
            ) : (
                <>
                    <span className="tabular-nums">{arrivals}</span>{' '}
                    {arrivals === 1
                        ? 'new notification'
                        : 'new notifications'}
                </>
            )}
        </button>
    </div>
)}
```

- [ ] **Step 5: Generate the action and run client verification**

Run:

```bash
php artisan wayfinder:generate --with-form
node --experimental-strip-types --test tests/Unit/NotificationSync.test.ts
bun run test:unit
bun run test:types
bun run test:lint
bun run build
```

Expected: reconciliation tests, all discovered TypeScript tests, application and test types, lint, and the production build pass.

- [ ] **Step 6: Inspect polling behavior and commit the page integration**

Run:

```bash
/home/shawn/.local/bin/ripwire . --edit-check=./resources/js/pages/notification/index.tsx:Index
git diff --check
git status --short
```

Verify from the diff that the old `through`, `CancelToken`, and `usePoll` client contract is gone; the existing anchor layout effect and `data-notification-group` attribute remain.

Commit:

```bash
git add resources/js/pages/notification/index.tsx
git commit -m "fix: bound notification inbox polling"
```

---

### Task 6: Verify, review, and submit the focused PR

**Files:**
- Review: every file listed in the File Map
- Do not commit: ignored Wayfinder output, dependency directories, build output, credentials, or unrelated worktree changes.

**Interfaces:**
- Consumes: all prior task commits.
- Produces: a reviewed branch and one upstream pull request for bounded notification synchronization, unless an equivalent open PR now exists.

- [ ] **Step 1: Run formatting, regenerate Wayfinder, and inspect formatter changes**

Run:

```bash
composer lint
php artisan wayfinder:generate --with-form
git diff --check
git status --short
```

Expected: formatting succeeds; only files in the File Map are tracked as changed. If formatting changed a committed file, inspect it and commit that scoped correction before continuing.

- [ ] **Step 2: Run the complete project gates**

Run:

```bash
composer test
bun run build
/home/shawn/.local/bin/ripwire . --quality-delta
/home/shawn/.local/bin/ripwire . --test-gate
```

Expected: lint, PHPStan, TypeScript, 100% PHP type coverage, 100% PHP test coverage, directly discovered JavaScript tests, production build, and both structural gates pass.

- [ ] **Step 3: Run targeted cross-database-sensitive and contract checks once more**

Run:

```bash
php -d memory_limit=-1 vendor/bin/pest tests/Unit/Queries/NotificationGroupsQueryTest.php tests/Feature/Controllers/NotificationSyncControllerTest.php tests/Feature/NotificationInboxTest.php
node --experimental-strip-types --test tests/Unit/NotificationSync.test.ts
php artisan route:list --name=notification-sync
```

Expected: all focused tests pass and the only matching route is authenticated `GET notifications/sync` named `notification-sync.index`.

- [ ] **Step 4: Request an independent whole-branch review and resolve every finding**

Invoke `superpowers:requesting-code-review`. Give the reviewer the design, this plan, and the full diff from `upstream/production...HEAD`. Require explicit review of:

```text
1. Recipient scoping, validation-before-acknowledgement, and cache privacy.
2. The 20 + 20 payload bound and absence of loaded-depth amplification.
3. One-statement representative identity and exact (group_id, id) continuity, including the mixed-burst counterexample.
4. Read acknowledgement races and stale Inertia deployment behavior.
5. Poll cancellation, overlap prevention, current-prop reconciliation, InfiniteScroll reset, sticky fallback, cursor preservation, and scroll anchoring.
```

After corrections, rerun the smallest affected test first, then repeat Steps 1 through 3.

- [ ] **Step 5: Recheck open pull requests and stop if an equivalent fix appeared**

Run:

```bash
gh pr list --repo nunomaduro/dev-null.sh --state open --limit 500 --json number,title,body,headRefName,url
```

Inspect the full result, including bodies, for notification, inbox, polling, sync, or retired-`through` candidates; do not rely only on an exact search phrase. If any candidate materially implements this bounded sync contract, do not push or open another PR; report the existing PR URL and retain the local branch for reference.

- [ ] **Step 6: Push to the fork and open a detailed plain-English PR**

Push this branch explicitly to the authenticated fork; do not use bare `git push`, because the local branch currently tracks the read-only `upstream` remote:

```bash
git push --set-upstream origin fix/bounded-notification-sync
```

Then create the PR explicitly from `eas4ai:fix/bounded-notification-sync` to `nunomaduro/dev-null.sh:production`:

```bash
gh pr create \
  --repo nunomaduro/dev-null.sh \
  --base production \
  --head eas4ai:fix/bounded-notification-sync \
  --title "Bound notification inbox polling work" \
  --body-file -
```

Send the following body on standard input and then close it:

```markdown
## Summary

- add a private notification sync endpoint with a fixed 20-row head and at most 20 visible-row refreshes
- reconcile safe updates by stable group ID and representative event ID while preserving the deepest pagination cursor
- select head, boundary, and visible representatives from one database statement and reset Inertia's internal cursor when a complete list is returned
- replace depth-dependent Inertia reload polling with non-overlapping JSON polling and a safe full-refresh fallback
- keep normal inbox pages fixed at 20 rows and remove the retired `through` request contract

## Why

The old poll sent the oldest loaded timestamp back to the server. Every additional page made later polls query, serialize, transfer, and replace a larger window. This change keeps hydration and response work bounded regardless of how far a person has scrolled.

## Safety

The endpoint is authenticated, recipient-scoped, validated before read acknowledgement, and marked `private, no-store`. The client applies a response only when an exact `(group_id, id)` boundary or unchanged representative prefix proves continuity. It reconciles against Inertia's latest page state so a concurrent infinite-scroll merge is not overwritten, and a complete response performs a server-backed cursor reset when needed. Ambiguous bursts leave the loaded list untouched and show `New notifications`, which performs a normal page-one reload.

Offscreen historical rows can remain stale until they become visible or the page is refreshed. Delayed transactions that become visible below a previously proven boundary have the same documented limitation. A persistent mutation version or change feed would be required for a stronger guarantee.

## Verification

- complete Composer test pipeline, including 100% PHP coverage and type coverage
- complete directly discovered TypeScript unit suite
- production frontend build
- focused query, controller, inbox, and reconciliation regression suites
- Ripwire quality-delta and test-gate checks
```

After creation, record the PR URL and monitor its checks. A fork-workflow `action_required` state means maintainer approval is pending; report it accurately rather than calling it a test failure.
