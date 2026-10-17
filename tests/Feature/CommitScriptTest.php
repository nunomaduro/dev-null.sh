<?php

declare(strict_types=1);

use App\Dto\SshPublicKey;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * These tests run the real `devnull commit` script with bash, in a sandbox: a fake HOME and TMPDIR,
 * and stubs for `curl`, `ssh-keygen` and `ssh-add` that log how they are called.
 */
beforeEach(function (): void {
    Process::preventStrayProcesses(false);

    $this->sandbox = sys_get_temp_dir().'/devnull-script-test-'.Str::random(10);
    $this->publicKey = SshPublicKey::fromOpenSsh(sshFixture('ed25519.pub'));

    File::makeDirectory("{$this->sandbox}/bin", recursive: true);
    File::makeDirectory("{$this->sandbox}/home/.ssh", recursive: true);
    File::makeDirectory("{$this->sandbox}/tmp");

    File::put("{$this->sandbox}/home/.ssh/id_ed25519", 'private key');
    File::put("{$this->sandbox}/home/.ssh/id_ed25519.pub", sshFixture('ed25519.pub'));
    File::put("{$this->sandbox}/home/notes.txt", 'Keep me.');
    File::put("{$this->sandbox}/tmp/keep-me.txt", 'Keep me.');

    File::put("{$this->sandbox}/keys-response", $this->publicKey->toOpenSsh()."\n");
    File::put("{$this->sandbox}/commit-response", "Committed.\n");
    File::put("{$this->sandbox}/calls.log", '');

    $stubs = [
        'curl' => <<<'BASH'
            echo "curl $*" >> "$SANDBOX/calls.log"
            output="" url=""
            while [ $# -gt 0 ]; do
              case "$1" in
                --output) output="$2"; shift 2 ;;
                --proto | --max-time | --speed-limit | --speed-time | --header | --write-out) shift 2 ;;
                --data-binary) cp "${2#@}" "$SANDBOX/sent-$(basename "${2#@}")"; shift 2 ;;
                --form) case "$2" in "images[]=@"*) cp "${2#images[]=@}" "$SANDBOX/sent-${2#images[]=@}" ;; esac; shift 2 ;;
                -*) shift ;;
                *) url="$1"; shift ;;
              esac
            done
            case "$url" in
              */api/cli/key-lookups) cat "$SANDBOX/keys-response" > "$output"; printf '%s' "${KEYS_STATUS:-200}" ;;
              */api/cli/commits) cat "$SANDBOX/commit-response" > "$output"; printf '%s' "${COMMIT_STATUS:-201}" ;;
            esac
            BASH,
        'ssh-keygen' => <<<'BASH'
            echo "ssh-keygen $*" >> "$SANDBOX/calls.log"
            [ -z "${SIGN_FAILS:-}" ] || exit 1
            for file; do :; done
            cp "$file" "$SANDBOX/signed"
            printf '%s\n' '-----BEGIN SSH SIGNATURE-----' "${SIGNATURE:-c2lnbmF0dXJl}" '-----END SSH SIGNATURE-----' > "$file.sig"
            BASH,
        'ssh-add' => <<<'BASH'
            echo "ssh-add $*" >> "$SANDBOX/calls.log"
            [ -f "$SANDBOX/agent-keys" ] && cat "$SANDBOX/agent-keys" || exit 2
            BASH,
    ];

    foreach ($stubs as $name => $stub) {
        File::put("{$this->sandbox}/bin/{$name}", "#!/usr/bin/env bash\n{$stub}\n");
        File::chmod("{$this->sandbox}/bin/{$name}", 0755);
    }

    $this->script = (string) $this->get(route('cli-script.show'))->getContent();

    $this->runScript = fn (string $script, array $arguments = ['It was DNS.'], array $env = []): ProcessResult => Process::input($script)
        ->env([
            'PATH' => "{$this->sandbox}/bin:/usr/bin:/bin",
            'HOME' => "{$this->sandbox}/home",
            'TMPDIR' => "{$this->sandbox}/tmp",
            'SANDBOX' => $this->sandbox,
            'SSH_AUTH_SOCK' => false,
            'DEVNULL_URL' => false,
            ...$env,
        ])
        ->timeout(10)
        ->run(['bash', '-s', '--', ...$arguments]);

    $this->calls = fn (): string => File::get("{$this->sandbox}/calls.log");

    $this->filesIn = fn (string $directory): array => collect(File::allFiles("{$this->sandbox}/{$directory}", hidden: true))
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->sort()
        ->values()
        ->all();
});

afterEach(function (): void {
    File::deleteDirectory($this->sandbox);
});

it('commits with a key from ~/.ssh', function (): void {
    $result = ($this->runScript)($this->script, ['It', 'was', 'DNS.']);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toBe("Committed.\n")
        ->and(($this->calls)())->toContain("ssh-keygen -Y sign -n devnull-commit -f {$this->sandbox}/home/.ssh/id_ed25519 ")
        ->and(File::get("{$this->sandbox}/sent-candidates"))->toBe($this->publicKey->toOpenSsh()."\n")
        ->and(File::get("{$this->sandbox}/sent-commit"))->toMatch('/^\d+\n[0-9a-f]{32}\n\nIt was DNS\.$/')
        ->and(mb_substr_count(($this->calls)(), '--header Content-Type: text/plain; charset=utf-8'))->toBe(2)
        ->and(($this->calls)())->toContain('--header Content-Type: text/plain; charset=utf-8 --header X-SSH-Signature: ');
});

it('replies to a commit', function (): void {
    $result = ($this->runScript)($this->script, ['--reply', '0be0130', 'Agreed!']);

    expect($result->exitCode())->toBe(0)
        ->and(File::get("{$this->sandbox}/sent-commit"))->toMatch('/^\d+\n[0-9a-f]{32}\n0be0130\nAgreed!$/');
});

it('requires a commit to reply to', function (): void {
    $result = ($this->runScript)($this->script, ['--reply']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toBe('devnull: Usage: curl -fsSL '.url('/')."/commit | bash -s -- --reply <commit hash or link> 'Your reply'\n")
        ->and(($this->calls)())->toBe('');
});

it('replies to one commit only', function (): void {
    $result = ($this->runScript)($this->script, ['--reply', '0be0130', '--reply', '1ce1241', 'Agreed!']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toBe("devnull: --reply can only be given once.\n")
        ->and(($this->calls)())->toBe('');
});

it('only accepts commit hashes and links to reply to', function (): void {
    $result = ($this->runScript)($this->script, ['--reply', "0be0130\nIt was not DNS.", 'Agreed!']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toBe("devnull: --reply takes the hash of a commit or its link.\n")
        ->and(($this->calls)())->toBe('');
});

it('signs through the ssh agent when it has the key', function (): void {
    File::put("{$this->sandbox}/agent-keys", $this->publicKey->toOpenSsh()." laptop\n");
    File::delete(["{$this->sandbox}/home/.ssh/id_ed25519", "{$this->sandbox}/home/.ssh/id_ed25519.pub"]);

    $result = ($this->runScript)($this->script);

    expect($result->exitCode())->toBe(0)
        ->and(($this->calls)())->toMatch('#ssh-keygen -Y sign -n devnull-commit -f '.preg_quote($this->sandbox, '#').'/tmp/devnull\.\w{10}/key\.pub #');
});

it('only deletes its own temporary files', function (): void {
    $home = ($this->filesIn)('home');

    $result = ($this->runScript)($this->script);

    expect($result->exitCode())->toBe(0)
        ->and(File::directories("{$this->sandbox}/tmp"))->toBe([])
        ->and(($this->filesIn)('tmp'))->toBe(['keep-me.txt'])
        ->and(($this->filesIn)('home'))->toBe($home)
        ->and(File::get("{$this->sandbox}/home/notes.txt"))->toBe('Keep me.');
});

it('cleans up when it fails', function (): void {
    $result = ($this->runScript)($this->script, env: ['COMMIT_STATUS' => '503']);

    expect($result->exitCode())->toBe(1)
        ->and(File::directories("{$this->sandbox}/tmp"))->toBe([])
        ->and(($this->filesIn)('tmp'))->toBe(['keep-me.txt']);
});

it('commits with images', function (): void {
    File::put("{$this->sandbox}/home/first.png", 'first image');
    File::put("{$this->sandbox}/home/a b,c;d\"e.png", 'second image');

    $result = ($this->runScript)($this->script, ['--reply', '0be0130', '--image', "{$this->sandbox}/home/first.png", '--image', "{$this->sandbox}/home/a b,c;d\"e.png", 'Shipped!']);

    [, , $replyTo, $hashes, $message] = explode("\n", File::get("{$this->sandbox}/signed"), 5);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toBe("Committed.\n")
        ->and($replyTo)->toBe('0be0130')
        ->and($hashes)->toBe(hash('sha256', 'first image').','.hash('sha256', 'second image'))
        ->and($message)->toBe('Shipped!')
        ->and(File::get("{$this->sandbox}/sent-image-1"))->toBe('first image')
        ->and(File::get("{$this->sandbox}/sent-image-2"))->toBe('second image')
        ->and(($this->calls)())->toContain('-n devnull-commit-images')
        ->toContain('--max-time 600 --speed-limit 1024 --speed-time 60 ')
        ->toContain('--form payload=@commit --form images[]=@image-1 --form images[]=@image-2')
        ->and(mb_substr_count(($this->calls)(), 'Content-Type'))->toBe(1)
        ->and(File::directories("{$this->sandbox}/tmp"))->toBe([])
        ->and(($this->filesIn)('tmp'))->toBe(['keep-me.txt']);
});

it('refuses images it cannot send', function (array $arguments, string $error): void {
    File::put("{$this->sandbox}/home/first.png", 'first image');

    $arguments = array_map(fn (string $argument): string => str_replace('SANDBOX', $this->sandbox, $argument), $arguments);

    $result = ($this->runScript)($this->script, $arguments);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toBe(str_replace(['SANDBOX', 'URL'], [$this->sandbox, url('/')], $error)."\n")
        ->and(($this->calls)())->toBe('');
})->with([
    'more than 3' => [[...array_merge(...array_fill(0, 4, ['--image', 'SANDBOX/home/first.png'])), 'Shipped!'], 'devnull: A commit can carry up to 3 images.'],
    'missing' => [['--image', 'SANDBOX/home/missing.png', 'Shipped!'], 'devnull: Could not read the image SANDBOX/home/missing.png.'],
    'a directory' => [['--image', 'SANDBOX/home', 'Shipped!'], 'devnull: Could not read the image SANDBOX/home.'],
    'no path' => [['--image'], "devnull: Usage: curl -fsSL URL/commit | bash -s -- --image <path> 'Your commit'"],
]);

it('cleans up the copies of the images when signing fails', function (): void {
    File::put("{$this->sandbox}/home/first.png", 'first image');

    $result = ($this->runScript)($this->script, ['--image', "{$this->sandbox}/home/first.png", 'Shipped!'], env: ['SIGN_FAILS' => '1']);

    expect($result->exitCode())->toBe(1)
        ->and(File::directories("{$this->sandbox}/tmp"))->toBe([])
        ->and(($this->filesIn)('tmp'))->toBe(['keep-me.txt']);
});

it('fails when it cannot hash an image', function (): void {
    File::put("{$this->sandbox}/home/first.png", 'first image');
    File::put("{$this->sandbox}/bin/shasum", "#!/usr/bin/env bash\nexit 1\n");
    File::chmod("{$this->sandbox}/bin/shasum", 0755);

    $result = ($this->runScript)($this->script, ['--image', "{$this->sandbox}/home/first.png", 'Shipped!']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toBe("devnull: Could not hash the image {$this->sandbox}/home/first.png.\n")
        ->and(($this->calls)())->not->toContain('ssh-keygen')
        ->and(($this->filesIn)('tmp'))->toBe(['keep-me.txt']);
});

it('cleans up the copies of the images when it fails', function (): void {
    File::put("{$this->sandbox}/home/first.png", 'first image');

    $result = ($this->runScript)($this->script, ['--image', "{$this->sandbox}/home/first.png", 'Shipped!'], env: ['COMMIT_STATUS' => '503']);

    expect($result->exitCode())->toBe(1)
        ->and(File::directories("{$this->sandbox}/tmp"))->toBe([])
        ->and(($this->filesIn)('tmp'))->toBe(['keep-me.txt']);
});

it('accepts images of up to 10 MB', function (): void {
    $image = "{$this->sandbox}/home/large.png";
    ftruncate($handle = fopen($image, 'w'), 10_240_000);
    fclose($handle);

    $result = ($this->runScript)($this->script, ['--image', $image, 'Shipped!']);

    expect($result->exitCode())->toBe(0);
});

it('refuses larger images before sending anything', function (): void {
    $image = "{$this->sandbox}/home/huge.png";
    ftruncate($handle = fopen($image, 'w'), 10_240_001);
    fclose($handle);

    $result = ($this->runScript)($this->script, ['--image', $image, 'Shipped!']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toBe("devnull: The image {$image} is larger than 10 MB.\n")
        ->and(($this->calls)())->toBe('');
});

it('never signs with a key it did not send', function (): void {
    File::put("{$this->sandbox}/keys-response", "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOtherKeyOtherKeyOtherKeyOtherKeyOtherKey\n");

    $result = ($this->runScript)($this->script);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toBe('devnull: Unexpected answer from '.url('/').".\n")
        ->and(($this->calls)())->not->toContain('ssh-keygen');
});

it('refuses to send commits over insecure connections', function (): void {
    $result = ($this->runScript)($this->script, env: ['DEVNULL_URL' => 'http://devnull.example']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toBe("devnull: Refusing to send your commit to http://devnull.example: it is not an https:// URL.\n")
        ->and(($this->calls)())->toBe('');
});

it('ignores ~/.curlrc and only talks https', function (): void {
    $result = ($this->runScript)($this->script, env: ['DEVNULL_URL' => 'https://devnull.example']);

    expect($result->exitCode())->toBe(0)
        ->and(($this->calls)())
        ->toContain('curl --disable --silent --show-error --proto =https --tlsv1.2 --max-time 30 ')
        ->toMatch('# https://devnull\.example/api/cli/key-lookups$#m')
        ->toMatch('# https://devnull\.example/api/cli/commits$#m')
        ->not->toContain('--location')
        ->not->toContain('--insecure');
});

it('only puts base64 in the signature header', function (): void {
    $result = ($this->runScript)($this->script, env: ['SIGNATURE' => "c2ln\rX-Injected: yes"]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toBe("devnull: ssh-keygen produced an unexpected signature.\n")
        ->and(($this->calls)())->toMatch('#/api/cli/key-lookups$#m')->not->toMatch('#/api/cli/commits$#m')
        ->and(File::directories("{$this->sandbox}/tmp"))->toBe([]);
});

it('strips terminal escape sequences from the answers of devnull', function (): void {
    File::put("{$this->sandbox}/commit-response", "\e]0;pwned\x07Committed.\e[2J\r\n");

    $result = ($this->runScript)($this->script);

    expect($result->output())->toBe("]0;pwnedCommitted.[2J\n");
});

it('prints the errors of devnull', function (): void {
    File::put("{$this->sandbox}/commit-response", "The body field must not be greater than 4000 characters.\n");

    $result = ($this->runScript)($this->script, env: ['COMMIT_STATUS' => '422']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toBe("devnull: The body field must not be greater than 4000 characters.\n");
});

it('runs nothing when the download is cut short', function (): void {
    $lines = explode("\n", mb_rtrim($this->script, "\n"));
    $lastLine = array_pop($lines);
    $body = implode("\n", $lines)."\n";

    $truncations = [
        ...array_map(fn (int $count): string => implode("\n", array_slice($lines, 0, $count))."\n", range(0, count($lines))),
        ...array_map(fn (int $length): string => $body.mb_substr($lastLine, 0, $length), range(1, mb_strlen($lastLine) - 1)),
    ];

    foreach ($truncations as $script) {
        // Insecure URL: if `main` ever runs, it stops right away and says so.
        $result = ($this->runScript)($script, env: ['DEVNULL_URL' => 'http://devnull.example']);

        $mainRan = str_contains($result->errorOutput(), 'devnull: Refusing');

        // Only the complete script, called without arguments ("main"), may run `main`.
        expect($mainRan)->toBe(mb_trim(mb_substr($script, mb_strlen($body))) === 'main')
            ->and(($this->calls)())->toBe('')
            ->and(File::directories("{$this->sandbox}/tmp"))->toBe([]);
    }

    expect($truncations)->toHaveCount(count($lines) + 1 + mb_strlen($lastLine) - 1);
});

it('does not contain recursive deletions', function (): void {
    expect($this->script)->not->toMatch('/\brm\s+(-\w*r|--recursive)/');
});
