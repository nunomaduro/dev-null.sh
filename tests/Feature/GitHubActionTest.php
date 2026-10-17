<?php

declare(strict_types=1);

use App\Dto\SshPublicKey;
use App\Jobs\UnfurlLink;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * These tests run the GitHub Action's script with bash and the real `ssh-agent`, `ssh-add` and `ssh-keygen`,
 * in a sandbox: a fake HOME and TMPDIR, and a `curl` stub that keeps what it sends instead of sending it.
 */
beforeEach(function (): void {
    Process::preventStrayProcesses(false);

    $this->sandbox = sys_get_temp_dir().'/devnull-action-test-'.Str::random(10);

    File::makeDirectory("{$this->sandbox}/bin", recursive: true);
    File::makeDirectory("{$this->sandbox}/home");
    File::makeDirectory("{$this->sandbox}/tmp");

    Process::run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'release-bot', '-f', "{$this->sandbox}/key"])->throw();

    $this->privateKey = File::get("{$this->sandbox}/key");
    $this->publicKey = SshPublicKey::fromOpenSsh(File::get("{$this->sandbox}/key.pub"));

    File::put("{$this->sandbox}/keys-response", $this->publicKey->toOpenSsh()."\n");
    File::put("{$this->sandbox}/commit-response", "Committed: https://dev-null.sh/@nunomaduro/commits/0be0130 (pending review, only you can see it until it is approved)\n");

    File::put("{$this->sandbox}/bin/curl", <<<'BASH'
        #!/usr/bin/env bash
        output="" url=""
        while [ $# -gt 0 ]; do
          case "$1" in
            --output) output="$2"; shift 2 ;;
            --header) case "$2" in "X-SSH-Signature: "*) printf '%s' "${2#X-SSH-Signature: }" > "$SANDBOX/sent-signature" ;; esac; shift 2 ;;
            --proto | --max-time | --speed-limit | --speed-time | --write-out) shift 2 ;;
            --data-binary) cp "${2#@}" "$SANDBOX/sent-$(basename "${2#@}")"; shift 2 ;;
            --form) case "$2" in "payload=@"*) cp "${2#payload=@}" "$SANDBOX/sent-commit" ;; "images[]=@"*) cp "${2#images[]=@}" "$SANDBOX/sent-${2#images[]=@}" ;; esac; shift 2 ;;
            -*) shift ;;
            *) url="$1"; shift ;;
          esac
        done
        case "$url" in
          */api/cli/key-lookups) cat "$SANDBOX/keys-response" > "$output"; printf '200' ;;
          */api/cli/commits) cat "$SANDBOX/commit-response" > "$output"; printf '%s' "${COMMIT_STATUS:-201}" ;;
        esac
        BASH);
    File::chmod("{$this->sandbox}/bin/curl", 0755);

    $this->runAction = fn (array $env = []): ProcessResult => Process::path(base_path())
        ->env([
            'PATH' => "{$this->sandbox}/bin:".getenv('PATH'),
            'HOME' => "{$this->sandbox}/home",
            'TMPDIR' => "{$this->sandbox}/tmp",
            'SANDBOX' => $this->sandbox,
            'SSH_AUTH_SOCK' => false,
            'GITHUB_ACTIONS' => 'true',
            'DEVNULL_URL' => 'https://dev-null.sh',
            'DEVNULL_SSH_KEY' => $this->privateKey,
            'DEVNULL_BODY' => "Released v2.0.0.\n\nhttps://github.com/nunomaduro/pest/releases/tag/v2.0.0",
            'DEVNULL_REPLY_TO' => '',
            ...$env,
        ])
        ->timeout(20)
        ->run(['bash', '.github/actions/commit/commit.sh']);
});

afterEach(function (): void {
    File::deleteDirectory($this->sandbox);
});

it('commits with the key from the secret, signed so devnull accepts it', function (): void {
    $result = ($this->runAction)();

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toBe("::notice title=devnull::Committed: https://dev-null.sh/@nunomaduro/commits/0be0130 (pending review, only you can see it until it is approved)\n")
        ->and($result->output().$result->errorOutput())->not->toContain('PRIVATE KEY')
        ->and(File::get("{$this->sandbox}/sent-candidates"))->toBe($this->publicKey->toOpenSsh()."\n")
        ->and(File::allFiles("{$this->sandbox}/home", hidden: true))->toBeEmpty();

    $user = User::factory()->create();
    SshKey::factory()->for($user)->withPublicKey($this->publicKey)->create();
    fakeGitHubSshKeys($user, signingKeys: [$this->publicKey]);
    Http::fake(['github.com/nunomaduro/pest/releases/*' => Http::response(status: 404)]);
    Queue::fake([UnfurlLink::class]);

    $this->call('POST', route('cli-commit.store'), server: [
        'CONTENT_TYPE' => 'text/plain; charset=utf-8',
        'HTTP_X_SSH_SIGNATURE' => File::get("{$this->sandbox}/sent-signature"),
    ], content: File::get("{$this->sandbox}/sent-commit"))->assertCreated();

    $commit = $user->commits()->sole();

    expect($commit->body)->toBe("Released v2.0.0.\n\nhttps://github.com/nunomaduro/pest/releases/tag/v2.0.0");

    Queue::assertPushed(UnfurlLink::class, fn (UnfurlLink $job): bool => $job->linkPreview->is($commit->linkPreview));
});

it('replies to a commit', function (): void {
    $result = ($this->runAction)(['DEVNULL_REPLY_TO' => '0be0130', 'DEVNULL_BODY' => 'Agreed!']);

    expect($result->exitCode())->toBe(0)
        ->and(File::get("{$this->sandbox}/sent-commit"))->toMatch('/^\d+\n[0-9a-f]{32}\n0be0130\nAgreed!$/');
});

it('attaches the images, one path per line, signed so devnull accepts them', function (): void {
    $first = UploadedFile::fake()->image('first.png', 20, 10)->getContent();
    $second = UploadedFile::fake()->image('second.png', 10, 20)->getContent();

    File::put("{$this->sandbox}/first.png", $first);
    File::put("{$this->sandbox}/second image.png", $second);

    $result = ($this->runAction)([
        'DEVNULL_IMAGES' => "{$this->sandbox}/first.png \r\n\r\n  {$this->sandbox}/second image.png  \r\n",
        'DEVNULL_BODY' => '--reply is not an option here',
    ]);

    expect($result->exitCode())->toBe(0)
        ->and(File::get("{$this->sandbox}/sent-image-1"))->toBe($first)
        ->and(File::get("{$this->sandbox}/sent-image-2"))->toBe($second)
        ->and(File::get("{$this->sandbox}/sent-commit"))->toEndWith("\n".hash('sha256', $first).','.hash('sha256', $second)."\n--reply is not an option here");

    $user = User::factory()->create();
    SshKey::factory()->for($user)->withPublicKey($this->publicKey)->create();
    fakeGitHubSshKeys($user, signingKeys: [$this->publicKey]);
    Storage::fake();

    $this->call('POST', route('cli-commit.store'), files: [
        'payload' => new UploadedFile("{$this->sandbox}/sent-commit", 'commit', test: true),
        'images' => [
            new UploadedFile("{$this->sandbox}/sent-image-1", 'image-1', test: true),
            new UploadedFile("{$this->sandbox}/sent-image-2", 'image-2', test: true),
        ],
    ], server: [
        'HTTP_X_SSH_SIGNATURE' => File::get("{$this->sandbox}/sent-signature"),
    ])->assertCreated();

    $commit = $user->commits()->sole();

    expect($commit->body)->toBe('--reply is not an option here')
        ->and($commit->images)->toHaveCount(2);
});

it('fails with the answer of devnull', function (): void {
    File::put("{$this->sandbox}/commit-response", "Slow down: 10 commits a minute. Even CI takes breaks.\n");

    $result = ($this->runAction)(['COMMIT_STATUS' => '429']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toBe("::error title=devnull::Slow down: 10 commits a minute. Even CI takes breaks.\n");
});

it('fails when the secret is not a private key without a passphrase', function (string $key): void {
    $result = ($this->runAction)(['DEVNULL_SSH_KEY' => $key]);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toBe("::error title=devnull::Could not load the ssh-key input: it must be a private Ed25519 or RSA key without a passphrase.\n")
        ->and(File::exists("{$this->sandbox}/sent-candidates"))->toBeFalse();
})->with([
    'a public key' => fn (): string => $this->publicKey->toOpenSsh(),
    'garbage' => 'not a key',
]);

it('requires a body', function (): void {
    $result = ($this->runAction)(['DEVNULL_BODY' => '']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toBe("::error title=devnull::The body input is empty.\n");
});
