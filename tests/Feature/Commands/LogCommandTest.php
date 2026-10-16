<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\User;

it('reads everyone log from terminal command', function (): void {
    $alice = User::factory()->create(['username' => 'alice']);
    $bob = User::factory()->create(['username' => 'bob']);

    $commitA = Commit::factory()->for($alice)->create([
        'body' => 'First message from Alice.',
        'status' => CommitStatus::Approved,
    ]);

    $commitB = Commit::factory()->for($bob)->create([
        'body' => 'Second message from Bob.',
        'status' => CommitStatus::Approved,
    ]);

    $this->artisan('log --everyone')
        ->expectsOutputToContain('@alice')
        ->expectsOutputToContain('First message from Alice.')
        ->expectsOutputToContain('@bob')
        ->expectsOutputToContain('Second message from Bob.')
        ->assertSuccessful();
});

it('reads a specific developer log from terminal command', function (): void {
    $alice = User::factory()->create(['username' => 'alice']);
    $bob = User::factory()->create(['username' => 'bob']);

    Commit::factory()->for($alice)->create([
        'body' => 'Alice unique post.',
        'status' => CommitStatus::Approved,
    ]);

    Commit::factory()->for($bob)->create([
        'body' => 'Bob unique post.',
        'status' => CommitStatus::Approved,
    ]);

    $this->artisan('log alice')
        ->expectsOutputToContain('@alice')
        ->expectsOutputToContain('Alice unique post.')
        ->doesntExpectOutputToContain('Bob unique post.')
        ->assertSuccessful();
});

it('handles @ prefix in username argument gracefully', function (): void {
    $alice = User::factory()->create(['username' => 'alice']);

    Commit::factory()->for($alice)->create([
        'body' => 'Prefixed username post.',
        'status' => CommitStatus::Approved,
    ]);

    $this->artisan('log @alice')
        ->expectsOutputToContain('@alice')
        ->expectsOutputToContain('Prefixed username post.')
        ->assertSuccessful();
});

it('fails cleanly when requested user is not found', function (): void {
    $this->artisan('log nonexistent_dev')
        ->expectsOutputToContain('User @nonexistent_dev not found.')
        ->assertFailed();
});

it('shows empty log message when no commits exist', function (): void {
    $this->artisan('log --everyone')
        ->expectsOutputToContain('No commits found in the log yet.')
        ->assertSuccessful();
});

it('limits the number of displayed commits with --limit option', function (): void {
    $user = User::factory()->create();

    for ($i = 1; $i <= 5; $i++) {
        Commit::factory()->for($user)->create([
            'body' => "Post number {$i}",
            'status' => CommitStatus::Approved,
            'created_at' => now()->subMinutes(10 - $i),
        ]);
    }

    $this->artisan('log --everyone --limit=2')
        ->expectsOutputToContain('Post number 5')
        ->expectsOutputToContain('Post number 4')
        ->doesntExpectOutputToContain('Post number 1')
        ->assertSuccessful();
});

it('respects --no-tui flag and outputs static git DAG rail', function (): void {
    $user = User::factory()->create(['username' => 'nuno']);

    Commit::factory()->for($user)->create([
        'body' => 'Testing non-interactive static output.',
        'status' => CommitStatus::Approved,
    ]);

    $this->artisan('log --everyone --no-tui')
        ->expectsOutputToContain('@nuno')
        ->expectsOutputToContain('Testing non-interactive static output.')
        ->expectsOutputToContain('Log:')
        ->assertSuccessful();
});

it('sanitizes malicious ANSI injection in commit bodies', function (): void {
    $user = User::factory()->create(['username' => 'attacker']);

    Commit::factory()->for($user)->create([
        'body' => "Exploit \033[2J\033[HCleaned text",
        'status' => CommitStatus::Approved,
    ]);

    $this->artisan('log --everyone')
        ->expectsOutputToContain('Exploit Cleaned text')
        ->doesntExpectOutputToContain("\033[2J")
        ->assertSuccessful();
});

it('launches interactive tui mode with --tui flag', function (): void {
    $alice = User::factory()->create(['username' => 'alice']);

    Commit::factory()->for($alice)->create([
        'body' => 'Interactive TUI commit test.',
        'status' => CommitStatus::Approved,
    ]);

    $this->artisan('log --tui')
        ->assertSuccessful();

    $this->artisan('log --as=@alice --tui')
        ->assertSuccessful();
});

it('posts a commit directly via cli with --post flag for regular user', function (): void {
    $alice = User::factory()->create(['username' => 'alice']);

    $this->artisan('log', [
        '--post' => 'Direct commit from CLI',
        '--as' => '@alice',
    ])
        ->expectsOutputToContain('Committed:')
        ->assertSuccessful();

    expect(Commit::query()->where('body', 'Direct commit from CLI')->exists())->toBeTrue();
});

it('posts an approved commit directly via cli with --post flag for admin user', function (): void {
    config()->set('app.admins', ['root_admin']);
    $admin = User::factory()->create(['username' => 'root_admin']);

    $this->artisan('log', [
        '--post' => 'Admin direct commit',
        '--as' => '@root_admin',
    ])
        ->expectsOutputToContain('Committed:')
        ->assertSuccessful();
});

it('posts a reply to an existing commit with --post and --reply', function (): void {
    $alice = User::factory()->create(['username' => 'alice']);
    $parent = Commit::factory()->for($alice)->create([
        'body' => 'Parent thread commit.',
        'status' => CommitStatus::Approved,
    ]);

    $this->artisan("log --post=\"Reply to commit\" --as=@alice --reply={$parent->id}")
        ->expectsOutputToContain('Committed:')
        ->assertSuccessful();

    $reply = Commit::query()->where('body', 'Reply to commit')->first();
    expect($reply)->not->toBeNull();
    expect($reply->parent_id)->toBe($parent->id);
});

it('fails direct post when reply target commit cannot be found', function (): void {
    $alice = User::factory()->create(['username' => 'alice']);

    $this->artisan('log --post="Invalid reply" --as=@alice --reply=nonexistent_hash_12345')
        ->expectsOutputToContain('Reply target commit nonexistent_hash_12345 not found.')
        ->assertFailed();
});

it('fails direct post when no user can be resolved', function (): void {
    Commit::query()->delete();
    User::query()->delete();

    $this->artisan('log --post="Orphan post"')
        ->expectsOutputToContain('Cannot commit: no user found. Provide --as=@username.')
        ->assertFailed();
});

it('falls back to default user when --as is omitted in direct post', function (): void {
    $alice = User::factory()->create(['username' => 'alice']);

    $this->artisan('log --post="Default user message"')
        ->expectsOutputToContain('Committed:')
        ->assertSuccessful();
});
