<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\LinkPreview;
use App\Models\User;

it('shows an approved commit to anyone', function (): void {
    $user = User::factory()->create([
        'username' => 'octocat',
        'name' => 'The Octocat',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/583231',
    ]);
    $commit = Commit::factory()->for($user)->create([
        'id' => '019a2b3c-4d5e-7f60-8a9b-0c1d2e3f4a5b',
        'body' => 'Shipped the public API',
    ]);

    $response = $this->getJson(route('api-commit.show', $commit));

    $response->assertOk()
        ->assertExactJson(['data' => [
            'id' => '019a2b3c-4d5e-7f60-8a9b-0c1d2e3f4a5b',
            'short_hash' => 'e3f4a5b',
            'body' => 'Shipped the public API',
            'parent_id' => null,
            'html_url' => route('commit.show', [$user, $commit]),
            'created_at' => now()->toIso8601ZuluString(),
            'stars_count' => 0,
            'replies_count' => 0,
            'link_preview' => null,
            'images' => [],
            'gif' => null,
            'author' => [
                'id' => $user->id,
                'username' => 'octocat',
                'name' => 'The Octocat',
                'avatar_url' => 'https://avatars.githubusercontent.com/u/583231',
                'bio' => $user->bio,
                'company' => $user->company,
                'location' => $user->location,
                'website' => $user->website,
                'github_url' => 'https://github.com/octocat',
                'html_url' => route('user.show', $user),
                'feed_url' => route('user-feed.show', $user),
                'created_at' => now()->toIso8601ZuluString(),
            ],
        ]]);
});

it('counts only approved replies and every star', function (): void {
    $commit = Commit::factory()->create();

    Commit::factory()->for($commit, 'parent')->count(2)->create();
    Commit::factory()->for($commit, 'parent')->pending()->create();
    Commit::factory()->for($commit, 'parent')->rejected()->create();
    Commit::factory()->for($commit, 'parent')->create()->delete();

    $commit->stars()->attach(User::factory()->count(3)->create());

    $this->getJson(route('api-commit.show', $commit))
        ->assertOk()
        ->assertJsonPath('data.replies_count', 2)
        ->assertJsonPath('data.stars_count', 3);
});

it('shows the parent of a reply only while the parent is public', function (Closure $hide, bool $isPublic): void {
    $parent = Commit::factory()->create();
    $reply = Commit::factory()->for($parent, 'parent')->create();

    $hide($parent);

    $this->getJson(route('api-commit.show', $reply))
        ->assertOk()
        ->assertJsonPath('data.parent_id', $isPublic ? $parent->id : null);
})->with([
    'approved' => [fn (Commit $parent): null => null, true],
    'pending' => [fn (Commit $parent): bool => $parent->forceFill(['status' => CommitStatus::Pending])->save(), false],
    'rejected' => [fn (Commit $parent): bool => $parent->forceFill(['status' => CommitStatus::Rejected])->save(), false],
    'deleted' => [fn (Commit $parent): ?bool => $parent->delete(), false],
]);

it('includes the link preview without internal fields', function (): void {
    $preview = LinkPreview::factory()->fetched()->create([
        'url' => 'https://github.com/nunomaduro',
        'title' => 'Nuno Maduro',
    ]);
    $commit = Commit::factory()->create(['link_preview_id' => $preview->id]);

    $this->getJson(route('api-commit.show', $commit))
        ->assertOk()
        ->assertJsonPath('data.link_preview', [
            'url' => 'https://github.com/nunomaduro',
            'title' => 'Nuno Maduro',
            'description' => $preview->description,
            'image_url' => $preview->image_url,
            'site_name' => $preview->site_name,
            'is_pending' => false,
            'embed' => null,
        ]);
});

it('includes the embed of the link preview', function (): void {
    $preview = LinkPreview::factory()->fetched()->create(['url' => 'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT']);
    $commit = Commit::factory()->create(['link_preview_id' => $preview->id]);

    $this->getJson(route('api-commit.show', $commit))
        ->assertOk()
        ->assertJsonPath('data.link_preview.embed', [
            'provider' => 'Spotify',
            'kind' => 'audio',
            'src' => 'https://open.spotify.com/embed/track/4cOdK2wGLETKBW3PvgPWqT',
            'aspect_ratio' => null,
            'height' => 152,
        ]);
});

it('includes the images of the commit in order', function (): void {
    $commit = Commit::factory()->create();
    $second = CommitImage::factory()->for($commit)->create(['position' => 1, 'width' => 640, 'height' => 480]);
    $first = CommitImage::factory()->for($commit)->create(['position' => 0, 'description' => 'A terminal showing a successful build.']);

    $this->getJson(route('api-commit.show', $commit))
        ->assertOk()
        ->assertDontSee($first->path)
        ->assertJsonPath('data.images', [
            ['id' => $first->id, 'width' => 1200, 'height' => 800, 'description' => 'A terminal showing a successful build.', 'url' => route('commit-image.show', $first)],
            ['id' => $second->id, 'width' => 640, 'height' => 480, 'description' => null, 'url' => route('commit-image.show', $second)],
        ]);
});

it('never exposes hidden user fields', function (): void {
    $user = User::factory()->create(['email' => 'octocat@github.com', 'preferred_lane' => 3]);
    $commit = Commit::factory()->for($user)->create();

    $this->getJson(route('api-commit.show', $commit))
        ->assertOk()
        ->assertDontSee('octocat@github.com')
        ->assertJsonMissingPath('data.author.email')
        ->assertJsonMissingPath('data.author.github_id')
        ->assertJsonMissingPath('data.author.preferred_lane')
        ->assertJsonMissingPath('data.author.remember_token');
});

it('returns not found for commits that are not public', function (Closure $factory): void {
    $commit = $factory();

    $this->getJson("/api/v1/commits/{$commit->id}")
        ->assertNotFound()
        ->assertJsonMissingPath('data');
})->with([
    'pending' => [fn (): Commit => Commit::factory()->pending()->create(['body' => 'Waiting for review'])],
    'rejected' => [fn (): Commit => Commit::factory()->rejected()->create(['body' => 'Waiting for review'])],
    'deleted' => [fn (): Commit => tap(Commit::factory()->create(['body' => 'Waiting for review']))->delete()],
]);

it('returns not found for unknown and malformed ids', function (string $id): void {
    $this->getJson("/api/v1/commits/{$id}")->assertNotFound();
})->with([
    'unknown' => ['01a13f6e-ebdd-72c7-bf9d-be2a9b2315a3'],
    'malformed' => ['not-a-uuid'],
]);

it('answers in json without being asked to', function (): void {
    $this->get('/api/v1/commits/01a13f6e-ebdd-72c7-bf9d-be2a9b2315a3')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json');
});

it('allows browsers on any origin to read it', function (): void {
    $commit = Commit::factory()->create();

    $this->getJson(route('api-commit.show', $commit), ['Origin' => 'https://example.com'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', '*');
});

it('limits each address to 60 requests a minute', function (): void {
    $commit = Commit::factory()->create();

    foreach (range(1, 60) as $attempt) {
        $this->getJson(route('api-commit.show', $commit))->assertOk();
    }

    $this->getJson(route('api-commit.show', $commit))
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', 60);

    $this->getJson(route('api-commit.show', $commit), ['REMOTE_ADDR' => '10.0.0.2'])->assertOk();
});

it('includes the gif of a commit', function (): void {
    $commit = Commit::factory()->withGiphy()->create();

    $this->getJson("/api/v1/commits/{$commit->id}")
        ->assertOk()
        ->assertJsonPath('data.gif.id', 'xT9IgzoKnwFNmISR8I')
        ->assertJsonPath('data.gif.url', 'https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy-downsized-medium.gif?cid=abc123')
        ->assertJsonPath('data.gif.width', 480);
});

it('includes a null gif for a plain commit', function (): void {
    $commit = Commit::factory()->create();

    $this->getJson("/api/v1/commits/{$commit->id}")
        ->assertOk()
        ->assertJsonPath('data.gif', null);
});
