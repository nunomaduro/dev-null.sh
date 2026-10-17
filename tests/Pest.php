<?php

declare(strict_types=1);

use App\Dto\SshPublicKey;
use App\Jobs\UnfurlLink;
use App\Models\User;
use App\Services\OpenAI;
use App\Services\SignedCommits;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\SshKeyPair;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Sleep::fake();

        $this->freezeTime();
    })
    ->in('Feature', 'Unit');

expect()->extend('toBeOne', fn () => $this->toBe(1));

function something(): void
{
    // ..
}

function sshFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/Fixtures/ssh/'.$name);
}

/**
 * A unit vector in one dimension and zero elsewhere, so two of them differ only in direction and their cosine
 * distance is exact: 0 for the same dimension, 1 for any other.
 *
 * @return list<float>
 */
function embeddingVector(int $dimension): array
{
    $vector = array_fill(0, OpenAI::EMBEDDING_DIMENSIONS, 0.0);
    $vector[$dimension] = 1.0;

    return $vector;
}

/**
 * @param  list<list<float>>  $embeddings
 * @return array{data: list<array{index: int, embedding: list<float>}>}
 */
function embeddingsResponse(array $embeddings): array
{
    return ['data' => array_map(
        fn (array $embedding, int $index): array => ['index' => $index, 'embedding' => $embedding],
        $embeddings,
        array_keys($embeddings),
    )];
}

/**
 * The decision model's answers: one predicate per candidate, in the order given, at even odds or better when the
 * verdict is that it is relevant. An empty list leaves every candidate unjudged, and so kept.
 *
 * @param  list<bool>  $verdicts
 * @return array{answers: list<array{type: string, name: string, probability: float}>}
 */
function relevanceResponse(array $verdicts): array
{
    return ['answers' => array_map(
        fn (bool $relevant, int $index): array => [
            'type' => 'predicate',
            'name' => 'post_'.($index + 1),
            'probability' => $relevant ? 1.0 : 0.0,
        ],
        $verdicts,
        array_keys($verdicts),
    )];
}

/**
 * The two requests a commit search makes: embedding the term, and the decision model's verdicts on the candidates. By
 * default every candidate is relevant, so only the filtering tests pass verdicts.
 *
 * @param  list<bool>  $verdicts
 */
function fakeSearch(array $verdicts = []): void
{
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(embeddingsResponse([embeddingVector(0)])),
        'api.openai.com/v1/decisions' => Http::response(relevanceResponse($verdicts)),
    ]);
}

/**
 * @param  array<string, mixed>  $account
 */
function fakeGitHubAccount(User $user, array $account = []): void
{
    $account = [
        'id' => (int) $user->github_id,
        'login' => 'octocat',
        'name' => 'The Octocat',
        'email' => 'octocat@github.com',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/583231?v=4',
        'bio' => 'I am the Octocat.',
        'company' => '@github',
        'location' => 'San Francisco',
        'blog' => 'https://github.blog',
        ...$account,
    ];

    Http::fake([
        "api.github.com/user/{$user->github_id}" => Http::response($account),
    ]);

    fakeGitHubSshKeys(User::factory()->make(['github_id' => $user->github_id, 'username' => $account['login']]));
}

/**
 * @param  list<SshPublicKey>  $keys
 * @param  list<SshPublicKey>  $signingKeys
 */
function fakeGitHubSshKeys(User $user, array $keys = [], array $signingKeys = []): void
{
    $toResponse = fn (array $publicKeys): array => array_map(
        fn (SshPublicKey $publicKey): array => ['id' => 1, 'key' => $publicKey->toOpenSsh()],
        $publicKeys,
    );

    Http::fake([
        "api.github.com/user/{$user->github_id}" => Http::response(['login' => $user->username]),
        "api.github.com/users/{$user->username}/keys*" => Http::response($toResponse($keys)),
        "api.github.com/users/{$user->username}/ssh_signing_keys*" => Http::response($toResponse($signingKeys)),
    ]);
}

/**
 * @param  list<User>  $following
 * @param  list<User>  $followers
 */
function fakeGitHubFollows(User $user, array $following = [], array $followers = []): void
{
    $toResponse = fn (array $users): array => array_map(
        fn (User $user): array => ['id' => (int) $user->github_id, 'login' => $user->username],
        $users,
    );

    Http::fake([
        "api.github.com/user/{$user->github_id}" => Http::response(['login' => $user->username]),
        "api.github.com/users/{$user->username}/following*" => Http::response($toResponse($following)),
        "api.github.com/users/{$user->username}/followers*" => Http::response($toResponse($followers)),
    ]);
}

function fakeGitHubSponsorsListing(bool $hasSponsorsListing = false): void
{
    Http::fake([
        'api.github.com/graphql' => Http::response(['data' => ['viewer' => [
            'hasSponsorsListing' => $hasSponsorsListing,
        ]]]),
    ]);
}

/**
 * @param  list<string>  $imageHashes
 * @return array{payload: string, signature: string}
 */
function signedCommit(SshKeyPair $keyPair, string $body, ?int $timestamp = null, ?string $nonce = null, string $replyTo = '', array $imageHashes = []): array
{
    $lines = [
        $timestamp ?? now()->getTimestamp(),
        $nonce ?? bin2hex(random_bytes(16)),
        $replyTo,
    ];

    if ($imageHashes !== []) {
        $lines[] = implode(',', $imageHashes);
    }

    $lines[] = $body;

    $payload = implode("\n", $lines);

    $namespace = $imageHashes === [] ? SignedCommits::Namespace : SignedCommits::ImagesNamespace;

    return ['payload' => $payload, 'signature' => $keyPair->sign($payload, $namespace)];
}

function fakeAvatar(): string
{
    $image = imagecreatetruecolor(4, 4);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 0, 0));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * @param  array<string, mixed>|null  $payload
 */
function failedJob(string $queue = 'default', ?CarbonInterface $failedAt = null, string $exception = "RuntimeException: Something broke\nStack trace:\n#0 {main}", ?array $payload = null): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => $queue,
        'payload' => json_encode($payload ?? ['uuid' => $uuid, 'displayName' => UnfurlLink::class, 'attempts' => 1]),
        'exception' => $exception,
        'failed_at' => $failedAt ?? now(),
    ]);

    return $uuid;
}

function queuedJob(string $queue, CarbonInterface $createdAt): void
{
    DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => json_encode(['uuid' => (string) Str::uuid(), 'displayName' => UnfurlLink::class]),
        'attempts' => 0,
        'available_at' => $createdAt->getTimestamp(),
        'created_at' => $createdAt->getTimestamp(),
    ]);
}
