<?php

declare(strict_types=1);

use App\Models\Bookmark;
use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\LinkPreview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->keyPair = apiKeyPairFor($this->user);
});

it('lists the latest commits of everyone, newest first', function (): void {
    $author = User::factory()->create([
        'username' => 'octocat',
        'name' => 'The Octocat',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/583231',
    ]);
    $older = Commit::factory()->for($author)->create(['body' => 'It was DNS.', 'created_at' => now()->subMinute()]);
    $newer = Commit::factory()->for($this->user)->pending()->create(['body' => 'Mine, pending']);
    Commit::factory()->for($older, 'parent')->count(2)->create();
    Commit::factory()->for($older, 'parent')->pending()->create();
    Commit::factory()->pending()->create();

    $response = signedApiRequest($this->keyPair, 'GET', route('api-log.show', 'latest'));

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.replies_count', 0)
        ->assertJsonPath('data.1', [
            'id' => $older->id,
            'short_hash' => mb_substr(str_replace('-', '', $older->id), -7),
            'body' => 'It was DNS.',
            'parent_id' => null,
            'html_url' => route('commit.show', [$author, $older]),
            'created_at' => now()->subMinute()->toIso8601ZuluString(),
            'stars_count' => 0,
            'replies_count' => 2,
            'link_preview' => null,
            'images' => [],
            'author' => [
                'id' => $author->id,
                'username' => 'octocat',
                'name' => 'The Octocat',
                'avatar_url' => 'https://avatars.githubusercontent.com/u/583231',
                'bio' => $author->bio,
                'company' => $author->company,
                'location' => $author->location,
                'website' => $author->website,
                'github_url' => 'https://github.com/octocat',
                'html_url' => route('user.show', $author),
                'feed_url' => route('user-feed.show', $author),
                'created_at' => now()->toIso8601ZuluString(),
                'lane' => $author->lane(),
            ],
            'body_segments' => [['type' => 'text', 'text' => 'It was DNS.']],
            'mentions' => [],
            'status' => 'approved',
            'is_pinned' => false,
            'starred' => false,
            'is_saved' => false,
            'deleted_at' => null,
        ])
        ->assertJsonPath('links.next', null)
        ->assertJsonPath('meta.next_cursor', null)
        ->assertJsonPath('meta.per_page', 20);
});

it('shows what the web shows on a commit', function (): void {
    $author = User::factory()->create(['username' => 'octocat', 'preferred_lane' => 4]);
    $linkPreview = LinkPreview::factory()->fetched()->create(['url' => 'https://laravel.com']);
    $commit = Commit::factory()->for($author)->for($linkPreview)->create(['body' => 'Ask @NunoMaduro about https://laravel.com']);
    $image = CommitImage::factory()->for($commit)->create(['width' => 640, 'height' => 480]);
    $commit->stars()->attach([$this->user->id, User::factory()->create()->id]);
    Bookmark::factory()->create(['user_id' => $this->user->id, 'commit_id' => $commit->id]);
    $author->forceFill(['pinned_commit_id' => $commit->id])->save();

    $response = signedApiRequest($this->keyPair, 'GET', route('api-log.show', 'latest'));

    $response->assertOk()
        ->assertJsonPath('data.0.stars_count', 2)
        ->assertJsonPath('data.0.starred', true)
        ->assertJsonPath('data.0.is_saved', true)
        ->assertJsonPath('data.0.is_pinned', true)
        ->assertJsonPath('data.0.mentions', [['username' => 'NunoMaduro', 'offset' => 4, 'length' => 11]])
        ->assertJsonPath('data.0.link_preview', [
            'url' => 'https://laravel.com',
            'title' => $linkPreview->title,
            'description' => $linkPreview->description,
            'image_url' => $linkPreview->image_url,
            'site_name' => $linkPreview->site_name,
            'embed' => null,
        ])
        ->assertJsonPath('data.0.images', [[
            'id' => $image->id,
            'width' => 640,
            'height' => 480,
            'description' => null,
            'url' => route('commit-image.show', $image),
        ]])
        ->assertJsonPath('data.0.author.lane', 4);
});

it('only shows the saves and stars of the user', function (): void {
    $commit = Commit::factory()->create();
    $commit->stars()->attach(User::factory()->create());
    Bookmark::factory()->create(['commit_id' => $commit->id]);

    $response = signedApiRequest($this->keyPair, 'GET', route('api-log.show', 'latest'));

    $response->assertOk()
        ->assertJsonPath('data.0.id', $commit->id)
        ->assertJsonPath('data.0.stars_count', 1)
        ->assertJsonPath('data.0.starred', false)
        ->assertJsonPath('data.0.is_saved', false);
});

it('lists the commits of the users followed and the user', function (): void {
    $followed = User::factory()->create();
    $this->user->following()->attach($followed);

    $mine = Commit::factory()->for($this->user)->create(['created_at' => now()->subMinute()]);
    $theirs = Commit::factory()->for($followed)->create();
    Commit::factory()->create();

    $response = signedApiRequest($this->keyPair, 'GET', route('api-log.show', 'following'));

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $theirs->id)
        ->assertJsonPath('data.1.id', $mine->id);
});

it('leaves deleted commits out of the log', function (): void {
    $kept = Commit::factory()->create();
    Commit::factory()->for($this->user)->create()->delete();

    $deleted = Commit::factory()->create(['created_at' => now()->subMinute()]);
    Commit::factory()->for($deleted, 'parent')->create();
    $deleted->delete();

    $response = signedApiRequest($this->keyPair, 'GET', route('api-log.show', 'latest'));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $kept->id);
});

it('lists commits 20 at a time with a cursor to the next page', function (): void {
    Commit::factory()->count(22)
        ->state(new Sequence(fn (Sequence $sequence): array => [
            'body' => "Commit {$sequence->index}",
            'created_at' => now()->subMinutes($sequence->index),
        ]))
        ->create();

    $first = signedApiRequest($this->keyPair, 'GET', route('api-log.show', 'latest'));

    $first->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('data.0.body', 'Commit 0')
        ->assertJsonPath('data.19.body', 'Commit 19');

    $second = signedApiRequest($this->keyPair, 'GET', route('api-log.show', ['latest', 'cursor' => $first->json('meta.next_cursor')]));

    $second->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.body', 'Commit 20')
        ->assertJsonPath('data.1.body', 'Commit 21')
        ->assertJsonPath('meta.next_cursor', null);
});

it('does not query each commit or author', function (): void {
    signedApiRequest($this->keyPair, 'GET', route('api-log.show', 'latest'))->assertOk();

    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        signedApiRequest($this->keyPair, 'GET', route('api-log.show', 'latest'))->assertOk();

        return count(DB::getQueryLog());
    };

    Commit::factory()->count(2)->create();
    $few = $queries();

    Commit::factory()->count(8)->create();

    expect($queries())->toBe($few);
});

it('returns not found for unknown logs', function (): void {
    $response = signedApiRequest($this->keyPair, 'GET', 'api/v1/log/unknown');

    $response->assertNotFound();
});

it('rejects requests that are not signed', function (): void {
    $this->getJson(route('api-log.show', 'latest'))->assertUnauthorized();
});
