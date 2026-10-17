<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

function atomFeed(TestResponse $response): SimpleXMLElement
{
    $feed = simplexml_load_string((string) $response->getContent());

    expect($feed)->toBeInstanceOf(SimpleXMLElement::class)
        ->and($feed->getName())->toBe('feed')
        ->and($feed->getNamespaces())->toBe(['' => 'http://www.w3.org/2005/Atom']);

    assert($feed instanceof SimpleXMLElement);

    return $feed;
}

it('serves the public top-level commits of a user as an atom feed', function (): void {
    $user = User::factory()->create([
        'username' => 'octocat',
        'name' => 'The Octocat',
        'bio' => 'I am the Octocat.',
    ]);

    $older = Commit::factory()->for($user)->create([
        'id' => '019a2b3c-4d5e-7f60-8a9b-0c1d2e3f4a5b',
        'body' => 'First commit',
        'created_at' => now()->subDay(),
    ]);
    $newer = Commit::factory()->for($user)->create(['body' => 'Second commit']);
    Commit::factory()->for($user)->for($older, 'parent')->create(['body' => 'A reply']);
    Commit::factory()->for($user)->pending()->create(['body' => 'Pending commit']);
    Commit::factory()->for($user)->rejected()->create(['body' => 'Rejected commit']);
    Commit::factory()->for($user)->create(['body' => 'Deleted commit'])->delete();
    Commit::factory()->create(['body' => 'Someone else']);

    $response = $this->get('/@octocat/feed.atom');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8')
        ->assertDontSee('A reply')
        ->assertDontSee('Pending commit')
        ->assertDontSee('Rejected commit')
        ->assertDontSee('Deleted commit')
        ->assertDontSee('Someone else');

    $feed = atomFeed($response);

    expect((string) $feed->id)->toBe("urn:uuid:{$user->id}")
        ->and((string) $feed->title)->toBe('The Octocat (@octocat) on /dev/null')
        ->and((string) $feed->subtitle)->toBe('I am the Octocat.')
        ->and((string) $feed->updated)->toBe(now()->toAtomString())
        ->and((string) $feed->author->name)->toBe('The Octocat')
        ->and((string) $feed->author->uri)->toBe(route('user.show', $user))
        ->and((string) $feed->link[0]['rel'])->toBe('self')
        ->and((string) $feed->link[0]['href'])->toBe(route('user-feed.show', $user))
        ->and((string) $feed->link[1]['rel'])->toBe('alternate')
        ->and((string) $feed->link[1]['href'])->toBe(route('user.show', $user))
        ->and($feed->entry)->toHaveCount(2)
        ->and((string) $feed->entry[0]->id)->toBe("urn:uuid:{$newer->id}")
        ->and((string) $feed->entry[1]->id)->toBe('urn:uuid:019a2b3c-4d5e-7f60-8a9b-0c1d2e3f4a5b')
        ->and((string) $feed->entry[1]->title)->toBe('First commit')
        ->and((string) $feed->entry[1]->link['href'])->toBe(route('commit.show', [$user, $older]))
        ->and((string) $feed->entry[1]->published)->toBe(now()->subDay()->toAtomString())
        ->and((string) $feed->entry[1]->updated)->toBe(now()->subDay()->toAtomString())
        ->and((string) $feed->entry[1]->content)->toBe('First commit')
        ->and((string) $feed->entry[1]->content['type'])->toBe('text');
});

it('attaches the images of commits as enclosures', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);
    $commit = Commit::factory()->for($user)->create();
    $second = CommitImage::factory()->for($commit)->create(['position' => 1]);
    $first = CommitImage::factory()->for($commit)->create(['position' => 0]);
    Commit::factory()->for($user)->create(['created_at' => now()->subDay()]);

    $feed = atomFeed($this->get('/@octocat/feed.atom'));

    expect($feed->entry[0]->link)->toHaveCount(3)
        ->and((string) $feed->entry[0]->link[1]['rel'])->toBe('enclosure')
        ->and((string) $feed->entry[0]->link[1]['type'])->toBe('image/webp')
        ->and((string) $feed->entry[0]->link[1]['href'])->toBe(route('commit-image.show', $first))
        ->and((string) $feed->entry[0]->link[2]['href'])->toBe(route('commit-image.show', $second))
        ->and($feed->entry[1]->link)->toHaveCount(1);
});

it('renders the feed without a query per commit', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);

    $create = fn (int $count) => Commit::factory()->for($user)->count($count)
        ->has(CommitImage::factory()->count(2)->sequence(['position' => 0], ['position' => 1]), 'images')
        ->create();

    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get('/@octocat/feed.atom')->assertOk();

        return count(DB::getQueryLog());
    };

    $create(1);
    $withOne = $queries();

    $create(5);

    expect($queries())->toBe($withOne);
});

it('lets readers cache the feed and revalidate it', function (): void {
    Commit::factory()->for(User::factory()->create(['username' => 'octocat']))->create();

    $response = $this->get('/@octocat/feed.atom');

    $etag = $response->headers->get('ETag');

    expect($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=300')
        ->and($etag)->toBeString();

    $this->get('/@octocat/feed.atom', ['If-None-Match' => $etag])->assertStatus(304);
});

it('limits the feed to the latest 50 commits', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);

    $commits = Commit::factory()->for($user)->count(51)->sequence(
        fn ($sequence): array => ['created_at' => now()->subMinutes($sequence->index)],
    )->create();

    $feed = atomFeed($this->get('/@octocat/feed.atom'));

    expect($feed->entry)->toHaveCount(50)
        ->and((string) $feed->entry[49]->id)->toBe("urn:uuid:{$commits[49]->id}");
});

it('titles entries with the start of long commits', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);
    $body = "Spent the afternoon reading the Atom spec\n\nso feed readers can follow devnull profiles without signing in.";

    Commit::factory()->for($user)->create(['body' => $body]);

    $feed = atomFeed($this->get('/@octocat/feed.atom'));

    expect((string) $feed->entry[0]->title)->toBe('Spent the afternoon reading the Atom spec so feed readers can follow…')
        ->and((string) $feed->entry[0]->content)->toBe($body);
});

it('escapes commits and profiles into valid xml', function (): void {
    $user = User::factory()->create([
        'username' => 'octocat',
        'name' => 'Tom & "Jerry" <script>',
        'bio' => null,
    ]);

    Commit::factory()->for($user)->create(['body' => "<script>alert('x')</script> & ]]> \u{1}\u{1F}bell\u{7}"]);

    $response = $this->get('/@octocat/feed.atom');

    $response->assertOk()
        ->assertDontSee('<script>', false);

    $feed = atomFeed($response);

    expect((string) $feed->author->name)->toBe('Tom & "Jerry" <script>')
        ->and($feed->subtitle)->toHaveCount(0)
        ->and((string) $feed->entry[0]->content)->toBe("<script>alert('x')</script> & ]]> bell");
});

it('serves an empty feed for users without public commits', function (): void {
    $user = User::factory()->create(['username' => 'octocat', 'created_at' => now()->subYear()]);

    Commit::factory()->for($user)->pending()->create();

    $feed = atomFeed($this->get('/@octocat/feed.atom'));

    expect($feed->entry)->toHaveCount(0)
        ->and((string) $feed->updated)->toBe(now()->subYear()->toAtomString());
});

it('returns not found for unknown usernames', function (): void {
    $this->get('/@missing/feed.atom')->assertNotFound();
});

it('lists an attached gif as an enclosure', function (): void {
    $user = User::factory()->create();
    Commit::factory()->for($user)->withGiphy()->create();

    $response = $this->get(route('user-feed.show', $user));

    $response->assertOk();
    expect($response->getContent())
        ->toContain('rel="enclosure"')
        ->toContain('type="image/gif"')
        ->toContain('https://media2.giphy.com/media/xT9IgzoKnwFNmISR8I/giphy-downsized-medium.gif?cid=abc123');
});
