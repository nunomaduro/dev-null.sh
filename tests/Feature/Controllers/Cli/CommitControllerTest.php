<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Jobs\UnfurlLink;
use App\Models\Commit;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\SshKeyPair;

/**
 * @param  array{payload: string, signature: string}  $commit
 */
function postSignedCommit(array $commit): TestResponse
{
    return test()->call('POST', route('cli-commit.store'), server: [
        'CONTENT_TYPE' => 'text/plain; charset=utf-8',
        'HTTP_X_SSH_SIGNATURE' => $commit['signature'],
    ], content: $commit['payload']);
}

beforeEach(function (): void {
    $this->keyPair = SshKeyPair::generate();
    $this->user = User::factory()->create();

    SshKey::factory()->for($this->user)->withPublicKey($this->keyPair->publicKey)->create();

    fakeGitHubSshKeys($this->user, [$this->keyPair->publicKey]);
});

it('creates a commit signed with an ssh key', function (): void {
    $response = postSignedCommit(signedCommit($this->keyPair, 'It was DNS.'));

    $commit = $this->user->commits()->sole();

    $response->assertCreated()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertContent('Committed: '.route('commit.show', [$this->user, $commit])." (pending review, only you can see it until it is approved)\n")
        ->assertCookieMissing(config('session.cookie'));

    expect($commit->body)->toBe('It was DNS.')
        ->and($commit->status)->toBe(CommitStatus::Pending);
});

it('collapses runs of blank lines in a signed commit', function (): void {
    postSignedCommit(signedCommit($this->keyPair, "It was DNS.\n\n\n\nIt is always DNS."))->assertCreated();

    expect($this->user->commits()->sole()->body)->toBe("It was DNS.\n\nIt is always DNS.");
});

it('links to the commit when it is published right away', function (): void {
    config(['app.admins' => [$this->user->username]]);

    $response = postSignedCommit(signedCommit($this->keyPair, 'It was DNS.'));

    $commit = $this->user->commits()->sole();

    $response->assertCreated()
        ->assertContent('Committed: '.route('commit.show', [$this->user, $commit])."\n");

    expect($commit->status)->toBe(CommitStatus::Approved);
});

it('replies to a commit', function (): void {
    $parent = Commit::factory()->create(['id' => '01a14036-b0fc-7085-95c9-6e52b0be0130']);

    $response = postSignedCommit(signedCommit($this->keyPair, 'Agreed!', replyTo: '0be0130'));

    $reply = $this->user->commits()->sole();

    $response->assertCreated();

    expect($reply->parent_id)->toBe($parent->id)
        ->and($reply->body)->toBe('Agreed!');
});

it('attaches a link preview for the first link', function (): void {
    Http::fake(['example.com/first' => Http::response(status: 404)]);
    Queue::fake([UnfurlLink::class]);

    postSignedCommit(signedCommit($this->keyPair, 'Shipped https://example.com/first and https://example.com/second'))->assertCreated();

    $commit = $this->user->commits()->sole();

    expect($commit->linkPreview->url)->toBe('https://example.com/first');

    Queue::assertPushed(UnfurlLink::class, fn (UnfurlLink $job): bool => $job->linkPreview->is($commit->linkPreview));
});

it('fails to reply to an unknown commit', function (): void {
    $response = postSignedCommit(signedCommit($this->keyPair, 'Agreed!', replyTo: '0be0130'));

    $response->assertUnprocessable()
        ->assertContent("There is no commit 0be0130 to reply to.\n");

    expect(Commit::query()->count())->toBe(0);
});

it('fails to reply across a block', function (): void {
    $parent = Commit::factory()->create(['id' => '01a14036-b0fc-7085-95c9-6e52b0be0130']);
    $parent->user->blocking()->attach($this->user);

    $response = postSignedCommit(signedCommit($this->keyPair, 'Agreed!', replyTo: '0be0130'));

    $response->assertUnprocessable()
        ->assertContent("There is no commit 0be0130 to reply to.\n");

    expect($parent->replies()->count())->toBe(0);
});

it('fails to reply to an ambiguous or malformed commit reference', function (string $replyTo, string $message): void {
    Commit::factory()->create(['id' => '01a14036-b0fc-7085-95c9-6e52b0be0130']);
    Commit::factory()->create(['id' => '01a14036-b0fc-7085-95c9-6e52c0be0130']);

    $response = postSignedCommit(signedCommit($this->keyPair, 'Agreed!', replyTo: $replyTo));

    $response->assertUnprocessable()
        ->assertContent($message."\n");

    expect($this->user->commits()->count())->toBe(0);
})->with([
    'ambiguous' => ['0be0130', '0be0130 matches several commits: use the link of the commit instead.'],
    'malformed' => ['not-a-hash', 'not-a-hash is not a commit hash or link.'],
]);

it('rejects invalid signatures', function (): void {
    $commit = signedCommit($this->keyPair, 'It was DNS.');

    $response = postSignedCommit([...$commit, 'payload' => $commit['payload'].'!']);

    $response->assertUnauthorized()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertContent("Invalid signature.\n");

    expect(Commit::query()->count())->toBe(0);
});

it('rejects requests without a signature', function (): void {
    $response = $this->call('POST', route('cli-commit.store'), content: 'It was DNS.');

    $response->assertUnauthorized()
        ->assertContent("Malformed SSH signature.\n");
});

it('rejects keys that are no longer on github', function (): void {
    $keyPair = SshKeyPair::generate();

    $response = postSignedCommit(signedCommit($keyPair, 'It was DNS.'));

    $response->assertUnauthorized()
        ->assertContent("This SSH key ({$keyPair->publicKey->fingerprint()}) is not on the GitHub account of a /dev/null user.\n");
});

it('validates the commit', function (string $body, string $message): void {
    $response = postSignedCommit(signedCommit($this->keyPair, $body));

    $response->assertUnprocessable()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertContent($message."\n");

    expect(Commit::query()->count())->toBe(0);
})->with([
    'blank' => ['    ', 'The body field is required.'],
    'too long' => [str_repeat('a', 4001), 'The body field must not be greater than 4000 characters.'],
]);

it('rate limits commits', function (): void {
    for ($i = 0; $i < 10; $i++) {
        postSignedCommit(signedCommit($this->keyPair, "Commit {$i}"))->assertCreated();
    }

    $response = postSignedCommit(signedCommit($this->keyPair, 'One too many'));

    $response->assertTooManyRequests()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertContent("Slow down: 10 commits a minute. Even CI takes breaks.\n");

    expect($this->user->commits()->count())->toBe(10);
});

it('fails when github cannot be reached', function (): void {
    $keyPair = SshKeyPair::generate();
    $user = User::factory()->create();

    SshKey::factory()->for($user)->withPublicKey($keyPair->publicKey)->create();

    Http::fake([
        "api.github.com/user/{$user->github_id}" => Http::response(['message' => 'Server Error'], 500),
    ]);

    $response = postSignedCommit(signedCommit($keyPair, 'It was DNS.'));

    $response->assertServiceUnavailable()
        ->assertContent("Could not reach GitHub to check your SSH key. Try again in a moment.\n");

    expect(Commit::query()->count())->toBe(0);
});

it('answers unknown commit routes in plain text', function (): void {
    $response = $this->get('api/cli/unknown');

    $response->assertNotFound()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertContent("Not Found\n");
});

it('keeps the default error pages elsewhere', function (): void {
    $response = $this->get('unknown');

    $response->assertNotFound();

    expect($response->headers->get('Content-Type'))->toStartWith('text/html');
});

it('answers unexpected errors in plain text', function (): void {
    Exceptions::fake();

    Route::get('api/cli/boom', fn () => throw new RuntimeException('Boom.'));

    $response = $this->get('api/cli/boom');

    $response->assertInternalServerError()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertContent("Internal Server Error\n");

    Exceptions::assertReported(RuntimeException::class);
});
