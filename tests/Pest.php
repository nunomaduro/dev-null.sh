<?php

declare(strict_types=1);

use App\Dto\SshPublicKey;
use App\Jobs\UnfurlLink;
use App\Models\Device;
use App\Models\Message;
use App\Models\User;
use App\Services\OpenAI;
use App\Services\SignedCommits;
use App\Services\SignedRequests;
use Carbon\CarbonInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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
 * @return array<string, mixed>
 */
function gitHubContributor(string $login, int $commits, string $type = 'User', ?int $id = null): array
{
    return ['login' => $login, 'id' => $id ?? crc32($login), 'contributions' => $commits, 'type' => $type];
}

/**
 * Serves the contributors of the repository like GitHub does: most commits first, a page at a time.
 *
 * @param  list<array<string, mixed>>  $contributors
 */
function fakeGitHubContributors(array $contributors): void
{
    Http::fake(['api.github.com/repos/'.config()->string('services.github.repository').'/contributors*' => function (Request $request) use ($contributors): PromiseInterface {
        $perPage = (int) $request['per_page'];

        return Http::response(array_slice($contributors, ((int) $request['page'] - 1) * $perPage, $perPage));
    }]);
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

/**
 * Sends a chat message the way the composer does, so tests start from real conversations.
 */
function sendChatMessage(User $sender, User $recipient, string $body = 'hello'): Message
{
    test()->actingAs($sender)->postJson(route('conversation-message.store', $recipient), [
        'body' => $body,
        'client_id' => Str::uuid()->toString(),
    ])->assertCreated();

    return Message::query()->latest('id')->firstOrFail();
}

/**
 * A WAV recording of a steady tone, in the voice note format unless told otherwise, with any extra chunks a recorder
 * might add (such as metadata) before the samples.
 */
function voiceNoteWav(int $milliseconds = 2000, int $sampleRate = 16000, int $channels = 1, int $amplitude = 8000, string $chunks = ''): string
{
    $frames = intdiv($sampleRate * $milliseconds, 1000);
    $samples = str_repeat(pack('v', $amplitude & 0xFFFF).pack('v', -$amplitude & 0xFFFF), intdiv($frames * $channels, 2));
    $format = pack('vvVVvv', 1, $channels, $sampleRate, $sampleRate * $channels * 2, $channels * 2, 16);

    return 'RIFF'.pack('V', 4 + 24 + mb_strlen($chunks, '8bit') + 8 + mb_strlen($samples, '8bit')).'WAVE'
        .'fmt '.pack('V', 16).$format
        .$chunks
        .'data'.pack('V', mb_strlen($samples, '8bit')).$samples;
}

/**
 * A WebM recording of Opus packets the way browsers record them live: the segment and its cluster of unknown size,
 * followed by tags the server must drop. Each packet is 20 ms of CELT, read from its first byte, with filler after it.
 */
function voiceNoteWebm(int $milliseconds = 2000, string $codec = 'A_OPUS', int $channels = 1, string $toc = "\xF8", int $flags = 0x80, int $tracks = 1, bool $blockGroups = false): string
{
    $blocks = collect(range(0, intdiv($milliseconds, 20) - 1))
        ->map(function (int $packet) use ($toc, $flags, $blockGroups): string {
            $block = "\x81".pack('n', $packet * 20).chr($flags).$toc.str_repeat("\x5A", 40);

            return $blockGroups ? ebmlElement(0xA0, ebmlElement(0xA1, $block)) : ebmlElement(0xA3, $block);
        })
        ->implode('');

    $entry = ebmlElement(0xAE, ebmlElement(0xD7, "\x01").ebmlElement(0x83, "\x02").ebmlElement(0x86, $codec).ebmlElement(0x63A2, 'OpusHead'.pack('CCvVvC', 1, $channels, 0, 48000, 0, 0)));

    return ebmlElement(0x1A45DFA3, ebmlElement(0x4282, 'webm'))
        .ebmlElement(0x18538067,
            ebmlElement(0x1549A966, ebmlElement(0x4D80, 'Chrome'))
            .ebmlElement(0x1654AE6B, str_repeat($entry, $tracks))
            .ebmlElement(0x1F43B675, ebmlElement(0xE7, "\x00").$blocks, unknownSize: true)
            .ebmlElement(0x1254C367, ebmlElement(0x7373, ebmlElement(0x67C8, ebmlElement(0x45A3, 'TITLE').ebmlElement(0x4487, 'secret title')))),
            unknownSize: true,
        );
}

function ebmlElement(int $id, string $data, bool $unknownSize = false): string
{
    return mb_ltrim(pack('N', $id), "\0", '8bit')
        .($unknownSize ? "\x01".str_repeat("\xFF", 7) : "\x01".mb_substr(pack('J', mb_strlen($data, '8bit')), 1, null, '8bit'))
        .$data;
}

function voiceNoteUpload(string $recording = ''): UploadedFile
{
    return UploadedFile::fake()->createWithContent('voice-note', $recording === '' ? voiceNoteWebm() : $recording);
}

/**
 * The waveform a browser sends along with a voice note.
 *
 * @return list<int>
 */
function voiceNoteWaveform(): array
{
    return array_fill(0, 48, 50);
}

/**
 * People who follow the maintainer, so they can be added to an org.
 *
 * @return list<User>
 */
function followersOf(User $maintainer, int $count): array
{
    return User::factory()->count($count)->create()->each(fn (User $follower) => $follower->following()->attach($maintainer))->all();
}

/**
 * Creates an org the way the Remotes page does, and returns its id.
 *
 * @param  list<User>  $members
 */
function createOrg(User $maintainer, array $members, ?string $name = 'dev/null core'): string
{
    return test()->actingAs($maintainer)->postJson(route('org.store'), [
        'name' => $name,
        'members' => array_map(fn (User $member): string => $member->username, $members),
    ])->assertCreated()->json('org.id');
}

function pushToOrg(User $sender, string $org, string $body = 'hello'): Message
{
    test()->actingAs($sender)->postJson(route('org-message.store', $org), [
        'body' => $body,
        'client_id' => Str::uuid()->toString(),
    ])->assertCreated();

    return Message::query()->latest('id')->firstOrFail();
}

/**
 * Signs an API request the way the mobile app does, and returns its headers as server variables.
 *
 * @return array<string, string>
 */
function signApiRequest(SshKeyPair $keyPair, string $method, string $uri, string $body = '', ?int $timestamp = null, ?string $nonce = null, string $namespace = 'devnull-api'): array
{
    $timestamp ??= now()->getTimestamp();
    $nonce ??= bin2hex(random_bytes(16));

    $query = parse_url(url($uri), PHP_URL_QUERY);
    $target = parse_url(url($uri), PHP_URL_PATH).(is_string($query) ? "?{$query}" : '');

    return [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_DEVNULL_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_DEVNULL_NONCE' => $nonce,
        'HTTP_X_SSH_SIGNATURE' => $keyPair->sign(implode("\n", [$timestamp, $nonce, mb_strtoupper($method), $target, $body]), $namespace),
    ];
}

/**
 * Sends an API request signed the way the mobile app does, with the data as its JSON body.
 *
 * @param  array<string, mixed>|null  $data
 */
function signedApiRequest(SshKeyPair $keyPair, string $method, string $uri, ?array $data = null, ?int $timestamp = null, ?string $nonce = null, string $namespace = 'devnull-api'): TestResponse
{
    $body = $data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR);

    return test()->call($method, $uri, server: signApiRequest($keyPair, $method, $uri, $body, $timestamp, $nonce, $namespace), content: $body);
}

/**
 * Sends an API request with images the way the mobile app does: multipart, with the data as the JSON payload beside the
 * images, signed with the hashes of $signedImages, which are the images sent unless a test says otherwise.
 *
 * @param  array<string, mixed>  $data
 * @param  list<UploadedFile>  $images
 * @param  list<UploadedFile>|null  $signedImages
 */
function signedApiRequestWithImages(SshKeyPair $keyPair, string $method, string $uri, array $data, array $images, ?array $signedImages = null, string $namespace = SignedRequests::ImagesNamespace): TestResponse
{
    $payload = json_encode($data, JSON_THROW_ON_ERROR);
    $hashes = implode(',', array_map(fn (UploadedFile $image): string => (string) hash_file('sha256', $image->getPathname()), $signedImages ?? $images));

    return test()->call($method, $uri, files: [
        'payload' => UploadedFile::fake()->createWithContent('payload', $payload),
        'images' => $images,
    ], server: [
        ...signApiRequest($keyPair, $method, $uri, $hashes."\n".$payload, namespace: $namespace),
        'CONTENT_TYPE' => 'multipart/form-data; boundary=devnull',
    ]);
}

/**
 * Gives the user a key approved as a device, that the mobile app signs API requests with. It isn't on their GitHub
 * account, as the API doesn't need it to be.
 */
function apiKeyPairFor(User $user): SshKeyPair
{
    $keyPair = SshKeyPair::generate();

    Device::factory()->for($user)->withPublicKey($keyPair->publicKey)->create();

    return $keyPair;
}
