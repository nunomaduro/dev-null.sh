<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\User;
use App\Services\Terminal\TerminalRenderer;

it('sanitizes user text against terminal display manipulation', function (): void {
    // Strips ANSI cursor / clear escape sequences...
    $malicious = "Hello \033[2J\033[HWorld\033[31m!";
    expect(TerminalRenderer::sanitize($malicious))->toBe('Hello World!');

    // Strips C0 control characters (BEL, NUL, BS, etc.)...
    $control = "Safe\x07\x00\x08Text";
    expect(TerminalRenderer::sanitize($control))->toBe('SafeText');

    // Preserves valid UTF-8 and emojis...
    $emoji = "Commit with 🔥 and 🚀 works\nsecond line";
    expect(TerminalRenderer::sanitize($emoji))->toBe("Commit with 🔥 and 🚀 works\nsecond line");
});

it('renders the ASCII banner', function (): void {
    $renderer = new TerminalRenderer();

    $colored = $renderer->banner(true);
    expect($colored)->toContain('/dev/null')
        ->toContain("\033[");

    $plain = $renderer->banner(false);
    expect($plain)->toContain('/dev/null')
        ->not->toContain("\033[");
});

it('renders empty log cleanly', function (): void {
    $renderer = new TerminalRenderer();

    $output = $renderer->log([], 'everyone', false);

    expect($output)
        ->toContain('Nobody has committed anything yet')
        ->toContain('php artisan log')
        ->not->toContain("\033[");
});

it('renders log with commit nodes and lane colors', function (): void {
    $user = User::factory()->create([
        'username' => 'nuno',
        'preferred_lane' => 0,
    ]);

    $commit = Commit::factory()->for($user)->create([
        'body' => 'Shipped to production.',
        'status' => CommitStatus::Approved,
    ]);

    $renderer = new TerminalRenderer();
    $output = $renderer->log([$commit], 'everyone', true);

    expect($output)
        ->toContain('@nuno')
        ->toContain('Shipped to production.')
        ->toContain(mb_substr($commit->id, 0, 7))
        ->toContain("\033[38;2;217;72;15m"); // Lane 0 orange
});

it('renders log with 16-color ANSI fallbacks', function (): void {
    $user = User::factory()->create([
        'username' => 'nuno',
        'preferred_lane' => 1,
    ]);

    $commit = Commit::factory()->for($user)->create([
        'body' => 'Standard ANSI terminal.',
        'status' => CommitStatus::Approved,
    ]);

    $renderer = new TerminalRenderer();
    $output = $renderer->log([$commit], 'everyone', color: true, force16: true);

    expect($output)
        ->toContain('@nuno')
        ->toContain("\033[32m"); // Lane 1 green in ANSI-16
});

it('renders user profile and stats in terminal format', function (): void {
    $user = User::factory()->create([
        'username' => 'taylor',
        'name' => 'Taylor Otwell',
        'bio' => 'Laravel creator.',
        'preferred_lane' => 3,
    ]);

    $commit = Commit::factory()->for($user)->create([
        'body' => 'Laravel release day.',
        'status' => CommitStatus::Approved,
    ]);

    $renderer = new TerminalRenderer();
    $output = $renderer->profile($user, [$commit], false);

    expect($output)
        ->toContain('@taylor (Taylor Otwell)')
        ->toContain('Laravel creator.')
        ->toContain('1 commits')
        ->toContain('Laravel release day.')
        ->not->toContain("\033[");
});

it('renders thread with ancestors, focused commit, and replies', function (): void {
    $user = User::factory()->create(['username' => 'brendt']);

    $parent = Commit::factory()->for($user)->create([
        'body' => 'Original commit.',
        'status' => CommitStatus::Approved,
    ]);

    $focused = Commit::factory()->for($user)->create([
        'parent_id' => $parent->id,
        'body' => 'In-between reply.',
        'status' => CommitStatus::Approved,
    ]);

    $reply = Commit::factory()->for($user)->create([
        'parent_id' => $focused->id,
        'body' => 'Bottom reply.',
        'status' => CommitStatus::Approved,
    ]);

    $renderer = new TerminalRenderer();
    $output = $renderer->thread($focused, [$parent], [$reply], false);

    expect($output)
        ->toContain('Original commit.')
        ->toContain('In-between reply.')
        ->toContain('(HEAD)')
        ->toContain('Bottom reply.')
        ->not->toContain("\033[");
});

it('honors NO_COLOR environment variable in supportsColor', function (): void {
    $renderer = new TerminalRenderer();

    $_SERVER['NO_COLOR'] = '1';
    expect($renderer->supportsColor())->toBeFalse();

    unset($_SERVER['NO_COLOR']);
    expect($renderer->supportsColor())->toBeTrue();
});

it('renders user profile with zero commits cleanly', function (): void {
    $user = User::factory()->create([
        'username' => 'newdev',
    ]);

    $renderer = new TerminalRenderer();
    $output = $renderer->profile($user, [], false);

    expect($output)
        ->toContain('@newdev has not committed anything yet.');
});
