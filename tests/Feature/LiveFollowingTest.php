<?php

declare(strict_types=1);

use App\Enums\LivePlatform;
use App\Enums\Log;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Commit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * The log's poll, reloading only the given props.
 */
function reloadLog(User $viewer, string $props = 'liveFollowing'): TestResponse
{
    return test()->actingAs($viewer)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) resolve(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'log/show',
        'X-Inertia-Partial-Data' => $props,
    ])->get(route('log.show', Log::Latest));
}

it('lists the people the user follows who are live, soonest ending first', function (): void {
    $viewer = User::factory()->create();
    $later = User::factory()->live(until: now()->addHours(3))->create();
    $sooner = User::factory()->live(LivePlatform::Twitch, until: now()->addHour())->count(2)->create()->sortBy('id')->values();
    $ended = User::factory()->live(until: now()->subMinute())->create();
    $offline = User::factory()->create();
    $stranger = User::factory()->live()->create();
    $viewer->following()->attach([$later->id, ...$sooner->modelKeys(), $ended->id, $offline->id]);
    $stranger->following()->attach($viewer);

    reloadLog($viewer)->assertOk()
        ->assertJsonPath('props.liveFollowing.*.id', [$sooner[0]->id, $sooner[1]->id, $later->id]);

    $this->travel(1)->hours();

    reloadLog($viewer)->assertOk()
        ->assertJsonPath('props.liveFollowing.*.id', [$later->id]);
});

it('sends each live person with only what the rail draws, their stream and latest commit', function (): void {
    $viewer = User::factory()->create();
    $live = User::factory()->live(LivePlatform::Twitch)->create();
    $commit = Commit::factory()->for($live)->create(['body' => str_repeat('a', 400), 'created_at' => now()->subMinute()]);
    $viewer->following()->attach($live);

    reloadLog($viewer)->assertOk()
        ->assertJsonPath('props.liveFollowing.0.username', $live->username)
        ->assertJsonPath('props.liveFollowing.0.lane', $live->lane())
        ->assertJsonPath('props.liveFollowing.0.live', [
            'url' => 'https://www.twitch.tv/octocat',
            'thumbnail_url' => 'https://static-cdn.jtvnw.net/previews-ttv/live_user_octocat-640x360.jpg',
            'platform' => 'twitch',
            'ends_at' => now()->addHours(2)->toIso8601ZuluString(),
            'embed' => [
                'provider' => 'Twitch',
                'kind' => 'video',
                'src' => 'https://player.twitch.tv/?channel=octocat&parent='.parse_url(config()->string('app.url'), PHP_URL_HOST).'&autoplay=true',
                'aspect_ratio' => '16 / 9',
                'height' => null,
                'poster' => null,
            ],
        ])
        ->assertJsonPath('props.liveFollowing.0.latest_commit', [
            'id' => $commit->id,
            'body' => Str::limit($commit->body, Commit::PREVIEW_LENGTH, '…'),
            'created_at' => now()->subMinute()->startOfSecond()->toJSON(),
        ])
        ->assertJsonMissingPath('props.liveFollowing.0.latest_commit_id')
        ->assertJsonMissingPath('props.liveFollowing.0.pivot')
        ->assertJsonMissingPath('props.liveFollowing.0.live_url')
        ->assertJsonMissingPath('props.liveFollowing.0.bio');
});

it('takes the newest approved top-level commit, skipping replies, pending and deleted commits', function (): void {
    $viewer = User::factory()->create();
    [$live, $quiet] = User::factory()->live()->count(2)->create()->sortBy('id')->values()->all();
    $viewer->following()->attach([$live->id, $quiet->id]);
    $latest = Commit::factory()->for($live)->create(['created_at' => now()->subHour()]);
    Commit::factory()->for($live)->create(['created_at' => now()->subHours(2)]);
    Commit::factory()->for($live)->for(Commit::factory(), 'parent')->create(['created_at' => now()->subMinutes(4)]);
    Commit::factory()->for($live)->pending()->create(['created_at' => now()->subMinutes(3)]);
    Commit::factory()->for($live)->create(['created_at' => now()->subMinutes(2)])->delete();
    Commit::factory()->for($quiet)->for(Commit::factory(), 'parent')->create();

    reloadLog($viewer)->assertOk()
        ->assertJsonPath('props.liveFollowing.0.latest_commit.id', $latest->id)
        ->assertJsonPath('props.liveFollowing.1.id', $quiet->id)
        ->assertJsonPath('props.liveFollowing.1.latest_commit', null);
});

it('leaves out the people the user mutes, blocks, is blocked by or unfollows', function (): void {
    $viewer = User::factory()->create();
    [$live, $muted, $blocked, $blocker, $unfollowed] = User::factory()->live()->count(5)->create()->all();
    $viewer->following()->attach([$live->id, $muted->id, $blocked->id, $blocker->id, $unfollowed->id]);
    $viewer->muting()->attach($muted);
    $viewer->blocking()->attach($blocked);
    $blocker->blocking()->attach($viewer);
    $viewer->following()->detach($unfollowed);

    reloadLog($viewer)->assertOk()
        ->assertJsonPath('props.liveFollowing.*.id', [$live->id]);
});

it('shows at most 24 people in two queries however many are live', function (): void {
    $viewer = User::factory()->create(['log' => Log::Latest, 'last_seen_at' => today()]);
    $queries = function () use ($viewer): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        reloadLog($viewer)->assertOk();

        return count(DB::getQueryLog());
    };
    $follow = function (int $count) use ($viewer): void {
        $people = User::factory()->live()->count($count)->has(Commit::factory()->count(2))->create();
        $viewer->following()->attach($people);
    };

    $follow(1);
    $expected = $queries();
    $follow(25);

    expect($queries())->toBe($expected);
    reloadLog($viewer)->assertJsonCount(24, 'props.liveFollowing');
});

it('only looks up live people when the log reloads them', function (): void {
    $viewer = User::factory()->create();
    $viewer->following()->attach(User::factory()->live()->create());

    DB::enableQueryLog();

    reloadLog($viewer, 'newCommitsCount')->assertOk()
        ->assertJsonMissingPath('props.liveFollowing');

    expect(collect(DB::getQueryLog())->pluck('query'))->each->not->toContain('live_ends_at');

    reloadLog($viewer)->assertOk()
        ->assertJsonCount(1, 'props.liveFollowing')
        ->assertJsonMissingPath('props.commits')
        ->assertJsonMissingPath('props.newCommitsCount');
});

it('shares live people on full page loads, but not with guests', function (): void {
    $viewer = User::factory()->create();
    $live = User::factory()->live()->create();
    $viewer->following()->attach($live);

    $this->get(route('log.show', Log::Latest))
        ->assertInertia(fn ($page) => $page->missing('liveFollowing'));

    $this->actingAs($viewer)->get(route('log.show', Log::Latest))
        ->assertInertia(fn ($page) => $page->where('liveFollowing.0.id', $live->id));
});
