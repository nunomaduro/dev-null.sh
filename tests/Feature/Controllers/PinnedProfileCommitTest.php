<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('keeps pins on the commits tab through pin replacement and unpinning', function (): void {
    $user = User::factory()->create();
    $first = Commit::factory()->for($user)->create(['created_at' => now()->subHour()]);
    $replacement = Commit::factory()->for($user)->create();
    $parent = Commit::factory()->create();
    $reply = Commit::factory()->for($user)->for($parent, 'parent')->create();
    $reader = User::factory()->create();

    foreach ([$first, $replacement, null] as $pin) {
        $this->actingAs($user);

        if ($pin instanceof Commit) {
            $this->put(route('commit-pin.update', $pin))->assertRedirect();
        } else {
            $this->delete(route('commit-pin.destroy', $replacement))->assertRedirect();
        }

        foreach ([$user, $reader] as $viewer) {
            $this->actingAs($viewer)->get(route('user.show', $user))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('tab', 'commits')
                    ->where($pin instanceof Commit ? 'pinnedCommit.id' : 'pinnedCommit', $pin?->id)
                    ->where('user.commits_count', 3)
                    ->where('commits.data', fn ($commits): bool => collect($commits)->pluck('id')->sort()->values()->all()
                        === collect([$first->id, $replacement->id])->reject(fn (string $id): bool => $id === $pin?->id)->sort()->values()->all()));

            $this->get(route('user.show', ['user' => $user, 'tab' => 'replies']))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('tab', 'replies')
                    ->where('pinnedCommit', null)
                    ->where('user.commits_count', 3)
                    ->has('commits.data', 1)
                    ->where('commits.data.0.id', $reply->id)
                    ->where('commits.data.0.parent.id', $parent->id));
        }
    }
});

it('shows a pinned commit once above the log with visible reply counts and unchanged activity', function (): void {
    $user = User::factory()->create(['preferred_lane' => 4]);
    $pinned = Commit::factory()->for($user)->create(['created_at' => now()->subHour()]);
    $normal = Commit::factory()->for($user)->create();
    Commit::factory()->for($pinned, 'parent')->create();
    Commit::factory()->for($pinned, 'parent')->pending()->create();
    Commit::factory()->for($pinned, 'parent')->rejected()->create();
    $user->update(['pinned_commit_id' => $pinned->id]);

    $viewer = User::factory()->create();
    $pinned->stars()->attach($viewer);
    $pinned->bookmarks()->create(['user_id' => $viewer->id]);

    $this->actingAs($viewer)->get(route('user.show', $user))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pinnedCommit.id', $pinned->id)
            ->where('pinnedCommit.user.lane', 4)
            ->where('pinnedCommit.replies_count', 1)
            ->where('pinnedCommit.stars_count', 1)
            ->where('pinnedCommit.starred', true)
            ->where('pinnedCommit.is_saved', true)
            ->missing('pinnedCommit.user.pinned_commit_id')
            ->missing('user.pinned_commit_id')
            ->where('user.commits_count', 2)
            ->where('activity.scale', 'day')
            ->where('activity.buckets', fn ($buckets): bool => collect($buckets)->sum('count') === 2)
            ->has('commits.data', 1)
            ->where('commits.data.0.is_saved', false)
            ->where('commits.data.0.id', $normal->id));
});

it('includes the quoted commit of a pinned quote', function (bool $signedIn): void {
    $user = User::factory()->create();
    $quoted = Commit::factory()->for(User::factory()->state(['username' => 'taylorotwell']))->create(['body' => 'The quoted commit']);
    $pinned = Commit::factory()->for($user)->for($quoted, 'quotedCommit')->create();
    $user->update(['pinned_commit_id' => $pinned->id]);

    if ($signedIn) {
        $this->actingAs(User::factory()->create());
    }

    $this->get(route('user.show', $user))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pinnedCommit.id', $pinned->id)
            ->where('pinnedCommit.quoted_commit.body', 'The quoted commit')
            ->where('pinnedCommit.quoted_commit.user.username', 'taylorotwell'));
})->with(['guest' => false, 'signed in' => true]);

it('keeps cursor ordering and excludes a pin on every page including tied timestamps', function (): void {
    $user = User::factory()->create();
    $pinned = Commit::factory()->for($user)->create(['created_at' => now()->subMonth()]);
    $commits = Commit::factory()->for($user)->count(45)->create();
    $user->update(['pinned_commit_id' => $pinned->id]);
    $this->actingAs($user);
    $url = route('user.show', $user);
    $seen = [];

    do {
        $response = $this->getJson($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request()),
        ]);
        $response->assertOk()->assertJsonPath('props.pinnedCommit.id', $pinned->id);
        $seen = [...$seen, ...array_column($response->json('props.commits.data'), 'id')];
        $url = $response->json('props.commits.next_page_url');
    } while ($url !== null);

    expect($seen)->toBe($commits->sortByDesc('id')->pluck('id')->all())
        ->not->toContain($pinned->id);
});

it('renders a profile whose only commit is pinned', function (): void {
    $user = User::factory()->create();
    $pinned = Commit::factory()->for($user)->create();
    $user->update(['pinned_commit_id' => $pinned->id]);

    $this->actingAs($user)->get(route('user.show', $user))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pinnedCommit.id', $pinned->id)
            ->has('commits.data', 0)
            ->where('commits.next_cursor', null)
            ->where('user.commits_count', 1));
});

it('only shows a pending or rejected pin to its owner', function (string $status): void {
    $user = User::factory()->create();
    $pinned = Commit::factory()->for($user)->create(['status' => $status, 'body' => 'Private pinned body']);
    $user->update(['pinned_commit_id' => $pinned->id]);

    $this->actingAs($user)->get(route('user.show', $user))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pinnedCommit.id', $pinned->id)
            ->where('pinnedCommit.status', $status)
            ->where('user.commits_count', 1)
            ->has('commits.data', 0));

    $viewer = User::factory()->create(['username' => 'admin']);
    config(['app.admins' => ['admin']]);

    $this->actingAs($viewer)->get(route('user.show', $user))
        ->assertOk()
        ->assertDontSee('Private pinned body')
        ->assertDontSee($pinned->id)
        ->assertInertia(fn ($page) => $page
            ->where('pinnedCommit', null)
            ->where('user.commits_count', 0)
            ->where('activity.buckets', fn ($buckets): bool => collect($buckets)->sum('count') === 0)
            ->has('commits.data', 0));
})->with(['pending', 'rejected']);

it('updates pin visibility when moderation changes', function (): void {
    $user = User::factory()->create();
    $pinned = Commit::factory()->for($user)->pending()->create();
    $user->update(['pinned_commit_id' => $pinned->id]);
    $this->actingAs(User::factory()->create());

    foreach ([CommitStatus::Approved, CommitStatus::Rejected, CommitStatus::Approved] as $status) {
        $pinned->update(['status' => $status]);
        $this->get(route('user.show', $user))
            ->assertOk()
            ->assertInertia(fn ($page) => $status === CommitStatus::Approved
                ? $page->where('pinnedCommit.id', $pinned->id)
                : $page->where('pinnedCommit', null));
    }
});

it('hides soft deleted pins and clears the reference on permanent deletion', function (): void {
    $user = User::factory()->create();
    $pinned = Commit::factory()->for($user)->create(['body' => 'Deleted pinned body']);
    $user->update(['pinned_commit_id' => $pinned->id]);

    $this->actingAs($user)->delete(route('commit.destroy', $pinned))->assertRedirect();

    foreach ([$user, User::factory()->create()] as $viewer) {
        $this->actingAs($viewer)->get(route('user.show', $user))
            ->assertOk()
            ->assertDontSee('Deleted pinned body')
            ->assertInertia(fn ($page) => $page
                ->where('pinnedCommit', null)
                ->where('user.commits_count', 0)
                ->where('activity.buckets', fn ($buckets): bool => collect($buckets)->sum('count') === 0)
                ->has('commits.data', 0));
    }

    $pinned->forceDelete();

    expect($user->fresh()->pinned_commit_id)->toBeNull();
});

it('does not expose a pin belonging to another user or a reply', function (bool $isReply): void {
    $user = User::factory()->create();
    $unavailable = $isReply
        ? Commit::factory()->for($user)->for(Commit::factory(), 'parent')->create()
        : Commit::factory()->create();
    $user->update(['pinned_commit_id' => $unavailable->id]);

    $this->actingAs($user)->get(route('user.show', $user))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pinnedCommit', null)
            ->has('commits.data', 0));
})->with([false, true]);

it('shows guests an approved pinned commit', function (): void {
    $user = User::factory()->create();
    $pinned = Commit::factory()->for($user)->create(['body' => 'Everyone may read this']);
    $user->update(['pinned_commit_id' => $pinned->id]);

    $this->get(route('user.show', $user))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('user/show')
            ->where('pinnedCommit.id', $pinned->id)
            ->where('pinnedCommit.is_saved', false)
            ->has('commits.data', 0));
});

it('keeps a pending pinned commit away from guests and pinned data out of user previews', function (): void {
    $user = User::factory()->create();
    $pinned = Commit::factory()->for($user)->pending()->create(['body' => 'Only its author may read this']);
    $user->update(['pinned_commit_id' => $pinned->id]);

    $this->get(route('user.show', $user))->assertOk()
        ->assertDontSee($pinned->body)
        ->assertInertia(fn ($page) => $page
            ->where('pinnedCommit', null)
            ->has('commits.data', 0));

    $this->actingAs(User::factory()->create())->getJson(route('user-preview.show', $user))
        ->assertOk()
        ->assertJsonMissingPath('user.pinned_commit_id')
        ->assertJsonMissingPath('pinnedCommit');
});

it('does not change the guest preview when only the pin changes', function (): void {
    $user = User::factory()->create();
    $pinned = Commit::factory()->for($user)->pending()->create();
    $version = fn (): string => Str::betweenFirst($this->get(route('user.show', $user))->getContent(), 'og.png?v=', '"');
    $initial = $version();

    foreach (['put', 'delete'] as $method) {
        $this->travel(2)->seconds();
        $this->actingAs($user)->{$method}(route('commit-pin.update', $pinned))->assertRedirect();
        $this->post(route('logout'))->assertRedirect();

        expect($version())->toBe($initial);
    }
});

it('does not resolve pins or activity during a commits-only partial reload', function (): void {
    $user = User::factory()->create();
    $pinned = Commit::factory()->for($user)->create();
    Commit::factory()->for($user)->create();
    $user->update(['pinned_commit_id' => $pinned->id]);

    $this->actingAs($user)->get(route('user.show', $user))->assertInertia(function ($page): void {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $page->reloadOnly('commits', fn ($reload) => $reload
            ->missing('user')
            ->missing('isFollowing')
            ->missing('activity')
            ->missing('pinnedCommit')
            ->has('commits.data', 1));
    });

    $queries = collect(DB::getQueryLog())->pluck('query');
    expect($queries->filter(fn (string $query): bool => str_starts_with($query, 'select "commits"."id", "commits"."user_id"')))->toHaveCount(1)
        ->and($queries->reject(fn (string $query): bool => str_starts_with($query, 'select "commits"."id", "commits"."user_id"') || str_starts_with($query, 'select * from (select "commits"."id"'))->all())
        ->each(fn ($query) => $query->not->toContain('count(*)')->not->toContain('exists'));
});

it('resets the scroll log when refreshing the pin and commits together', function (): void {
    $user = User::factory()->create();
    $pinned = Commit::factory()->for($user)->create();
    $user->update(['pinned_commit_id' => $pinned->id]);

    $this->actingAs($user)->getJson(route('user.show', $user), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request()),
        'X-Inertia-Partial-Component' => 'user/show',
        'X-Inertia-Partial-Data' => 'commits,pinnedCommit',
        'X-Inertia-Reset' => 'commits',
    ])->assertOk()
        ->assertJsonPath('props.pinnedCommit.id', $pinned->id)
        ->assertJsonPath('props.commits.data', [])
        ->assertJsonMissingPath('props.activity')
        ->assertJsonMissingPath('props.user')
        ->assertJsonPath('scrollProps.commits.reset', true);
});

it('includes the images of the pinned commit', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();
    $image = CommitImage::factory()->for($commit)->create();
    $user->forceFill(['pinned_commit_id' => $commit->id])->save();

    $this->actingAs(User::factory()->create())->get(route('user.show', $user))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pinnedCommit.id', $commit->id)
            ->has('pinnedCommit.images', 1)
            ->where('pinnedCommit.images.0.id', $image->id));
});
