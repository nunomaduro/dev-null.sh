<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Http\Middleware\AuthenticateSshSignature;
use App\Jobs\UnfurlLink;
use App\Models\Commit;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Symfony\Component\HttpFoundation\Response;
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

/**
 * @param  array{payload: string, signature: string}  $commit
 * @param  list<UploadedFile>  $images
 */
function postSignedCommitWithImages(array $commit, array $images): TestResponse
{
    return test()->call('POST', route('cli-commit.store'), files: [
        'payload' => UploadedFile::fake()->createWithContent('commit', $commit['payload']),
        'images' => $images,
    ], server: [
        'HTTP_X_SSH_SIGNATURE' => $commit['signature'],
    ]);
}

/**
 * @param  list<UploadedFile>  $images
 * @return list<string>
 */
function imageHashes(array $images): array
{
    return array_map(fn (UploadedFile $image): string => (string) hash_file('sha256', $image->getRealPath()), $images);
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

it('creates a commit with images', function (): void {
    Storage::fake();

    $images = [
        UploadedFile::fake()->image('first.jpg', 40, 30),
        UploadedFile::fake()->image('second.png', 10, 10),
    ];

    $response = postSignedCommitWithImages(signedCommit($this->keyPair, 'Shipped!', imageHashes: imageHashes($images)), $images);

    $commit = $this->user->commits()->sole();

    $response->assertCreated()
        ->assertContent('Committed: '.route('commit.show', [$this->user, $commit])." (pending review, only you can see it until it is approved)\n");

    expect($commit->body)->toBe('Shipped!')
        ->and($commit->images)->toHaveCount(2)
        ->and(Storage::disk()->allFiles('commit-images'))->toHaveCount(2);
});

it('authenticates images as php hands them over', function (): void {
    $image = UploadedFile::fake()->image('first.jpg', 10, 10);
    $commit = signedCommit($this->keyPair, 'Shipped!', imageHashes: imageHashes([$image]));

    $payload = UploadedFile::fake()->createWithContent('commit', $commit['payload']);

    $request = Request::create(route('cli-commit.store'), 'POST', files: [
        'payload' => new SymfonyUploadedFile($payload->getRealPath(), 'commit', test: true),
        'images' => [new SymfonyUploadedFile($image->getRealPath(), 'first.jpg', 'image/jpeg', test: true)],
    ], server: ['HTTP_X_SSH_SIGNATURE' => $commit['signature']]);

    $response = resolve(AuthenticateSshSignature::class)->handle($request, fn (Request $request): Response => response($request->string('body')->value()));

    expect($response->getContent())->toBe('Shipped!');
});

it('creates a commit with images whose body ends with whitespace', function (string $body): void {
    Storage::fake();

    $images = [UploadedFile::fake()->image('first.jpg', 10, 10)];

    postSignedCommitWithImages(signedCommit($this->keyPair, $body, imageHashes: imageHashes($images)), $images)->assertCreated();

    expect($this->user->commits()->sole()->body)->toBe('Shipped!');
})->with([
    'trailing newline' => "Shipped!\n",
    'trailing space' => 'Shipped! ',
]);

it('replies to a commit with images', function (): void {
    Storage::fake();

    $parent = Commit::factory()->create(['id' => '01a14036-b0fc-7085-95c9-6e52b0be0130']);
    $images = [UploadedFile::fake()->image('first.jpg', 10, 10)];

    postSignedCommitWithImages(signedCommit($this->keyPair, 'Agreed!', replyTo: '0be0130', imageHashes: imageHashes($images)), $images)->assertCreated();

    $reply = $this->user->commits()->sole();

    expect($reply->parent_id)->toBe($parent->id)
        ->and($reply->images)->toHaveCount(1);
});

it('reads a commit without images from the raw body, even when it looks like a form', function (): void {
    $commit = signedCommit($this->keyPair, 'Shipped & payload=nothing');

    $response = $this->call('POST', route('cli-commit.store'), parameters: ['payload' => 'nothing'], server: [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_X_SSH_SIGNATURE' => $commit['signature'],
    ], content: $commit['payload']);

    $response->assertCreated();

    expect($this->user->commits()->sole()->body)->toBe('Shipped & payload=nothing');
});

it('rejects a commit without images sent as a form', function (): void {
    $commit = signedCommit($this->keyPair, 'Shipped!');

    $response = $this->call('POST', route('cli-commit.store'), parameters: ['payload' => $commit['payload']], server: [
        'HTTP_X_SSH_SIGNATURE' => $commit['signature'],
    ]);

    $response->assertUnauthorized()
        ->assertContent("Invalid signature.\n");

    expect(Commit::query()->count())->toBe(0);
});

it('rejects images sent without their payload', function (): void {
    $images = [UploadedFile::fake()->image('first.jpg', 10, 10)];
    $commit = signedCommit($this->keyPair, 'Shipped!', imageHashes: imageHashes($images));

    $response = postSignedCommitWithImages([...$commit, 'payload' => ''], $images);

    $response->assertUnauthorized()
        ->assertContent("Invalid signature.\n");

    expect(Commit::query()->count())->toBe(0);
});

it('keeps the images in the order they were signed', function (): void {
    Storage::fake();

    $images = [
        UploadedFile::fake()->image('wide.jpg', 40, 10),
        UploadedFile::fake()->image('tall.jpg', 10, 40),
    ];

    postSignedCommitWithImages(signedCommit($this->keyPair, 'Shipped!', imageHashes: imageHashes(array_reverse($images))), $images)
        ->assertUnauthorized()
        ->assertContent("The attached images do not match the signed payload.\n");

    postSignedCommitWithImages(signedCommit($this->keyPair, 'Shipped!', imageHashes: imageHashes($images)), $images)->assertCreated();

    expect($this->user->commits()->sole()->images->pluck('width')->all())->toBe([40, 10]);
});

it('rejects a payload sent as a text field next to the images', function (): void {
    $images = [UploadedFile::fake()->image('first.jpg', 10, 10)];
    $commit = signedCommit($this->keyPair, 'Shipped!', imageHashes: imageHashes($images));

    $response = $this->call('POST', route('cli-commit.store'), parameters: ['payload' => $commit['payload']], files: ['images' => $images], server: [
        'HTTP_X_SSH_SIGNATURE' => $commit['signature'],
    ]);

    $response->assertUnauthorized()
        ->assertContent("Invalid signature.\n");

    expect(Commit::query()->count())->toBe(0);
});

it('rejects images that do not match the signed payload', function (): void {
    $images = [UploadedFile::fake()->image('first.jpg', 10, 10)];

    $response = postSignedCommitWithImages(signedCommit($this->keyPair, 'Shipped!', imageHashes: [hash('sha256', 'another image')]), $images);

    $response->assertUnauthorized()
        ->assertContent("The attached images do not match the signed payload.\n");

    expect(Commit::query()->count())->toBe(0);
});

it('rejects images attached to a commit signed without them', function (): void {
    $images = [UploadedFile::fake()->image('first.jpg', 10, 10)];

    $response = postSignedCommitWithImages(signedCommit($this->keyPair, 'Shipped!'), $images);

    $response->assertUnauthorized()
        ->assertContent("Invalid signature.\n");

    expect(Commit::query()->count())->toBe(0);
});

it('rejects a nested images field', function (): void {
    $commit = signedCommit($this->keyPair, 'Shipped!', imageHashes: [hash('sha256', 'an image')]);

    $response = $this->call('POST', route('cli-commit.store'), files: [
        'payload' => UploadedFile::fake()->createWithContent('commit', $commit['payload']),
        'images' => [[UploadedFile::fake()->image('first.jpg', 10, 10)]],
    ], server: [
        'HTTP_X_SSH_SIGNATURE' => $commit['signature'],
    ]);

    $response->assertUnauthorized()
        ->assertContent("The attached images could not be read.\n");

    expect(Commit::query()->count())->toBe(0);
});

it('rejects a commit signed with more than 3 images', function (): void {
    $images = [
        UploadedFile::fake()->image('first.jpg', 10, 10),
        UploadedFile::fake()->image('second.jpg', 10, 10),
        UploadedFile::fake()->image('third.jpg', 10, 10),
        UploadedFile::fake()->image('fourth.jpg', 10, 10),
    ];

    $response = postSignedCommitWithImages(signedCommit($this->keyPair, 'Shipped!', imageHashes: imageHashes($images)), $images);

    $response->assertUnauthorized()
        ->assertContent("Malformed payload.\n");

    expect(Commit::query()->count())->toBe(0);
});

it('rejects more than 3 images before looking at any of them', function (): void {
    $images = [
        UploadedFile::fake()->image('first.jpg', 10, 10),
        UploadedFile::fake()->image('second.jpg', 10, 10),
        UploadedFile::fake()->image('third.jpg', 10, 10),
    ];
    $failed = new UploadedFile($images[0]->getRealPath(), 'fourth.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, test: true);

    $response = postSignedCommitWithImages(signedCommit($this->keyPair, 'Shipped!', imageHashes: imageHashes($images)), [...$images, $failed]);

    $response->assertUnauthorized()
        ->assertContent("The attached images do not match the signed payload.\n");

    expect(Commit::query()->count())->toBe(0);
});

it('rejects an image that failed to upload', function (): void {
    $image = UploadedFile::fake()->image('first.jpg', 10, 10);
    $failed = new UploadedFile($image->getRealPath(), 'first.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, test: true);

    $response = postSignedCommitWithImages(signedCommit($this->keyPair, 'Shipped!', imageHashes: imageHashes([$image])), [$failed]);

    $response->assertUnprocessable()
        ->assertContent("The image failed to upload.\n");

    expect(Commit::query()->count())->toBe(0);
});

it('rejects a file that is not an image', function (): void {
    $images = [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')];

    $response = postSignedCommitWithImages(signedCommit($this->keyPair, 'Shipped!', imageHashes: imageHashes($images)), $images);

    $response->assertUnprocessable()
        ->assertContent("The images.0 field must be an image.\n");

    expect(Commit::query()->count())->toBe(0);
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
    'too short' => ['  ab  ', 'The body field must be at least 3 characters.'],
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
