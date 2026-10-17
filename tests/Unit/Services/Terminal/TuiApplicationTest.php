<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Enums\TuiTab;
use App\Models\Commit;
use App\Models\SshKey;
use App\Models\User;
use App\Services\Terminal\TuiApplication;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\EventProvider\ArrayEventProvider;
use PhpTui\Term\KeyCode;
use PhpTui\Term\Painter\StringPainter;
use PhpTui\Term\RawMode\TestRawMode;
use PhpTui\Term\Terminal as PhpTermTerminal;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Backend;
use PhpTui\Tui\Display\Backend\DummyBackend;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\DisplayBuilder;

it('initializes and switches tabs cleanly', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create([
        'status' => CommitStatus::Approved,
    ]);

    $app = new TuiApplication(collect([$commit]), $user);

    expect($app->activeTab)->toBe(TuiTab::Everyone);

    $app->handleEvent(CharKeyEvent::new('2'));
    expect($app->activeTab)->toBe(TuiTab::Following);

    $app->handleEvent(CharKeyEvent::new('5'));
    expect($app->activeTab)->toBe(TuiTab::Profile);

    $app->handleEvent(CharKeyEvent::new('?'));
    expect($app->activeTab)->toBe(TuiTab::Help);

    $app->handleEvent(CharKeyEvent::new('1'));
    expect($app->activeTab)->toBe(TuiTab::Everyone);

    $app->handleEvent(CharKeyEvent::new('4'));
    expect($app->activeTab)->toBe(TuiTab::Compose);
});

it('navigates feed selection with up, down, home, and end keys', function (): void {
    $user = User::factory()->create();
    $commits = Commit::factory()->count(5)->for($user)->create([
        'status' => CommitStatus::Approved,
    ]);

    $app = new TuiApplication($commits, $user);

    expect($app->selectedIndex)->toBe(0);

    // Down arrow or 'j'...
    $app->handleEvent(CodedKeyEvent::new(KeyCode::Down));
    expect($app->selectedIndex)->toBe(1);

    $app->handleEvent(CharKeyEvent::new('j'));
    expect($app->selectedIndex)->toBe(2);

    // Up arrow or 'k'...
    $app->handleEvent(CodedKeyEvent::new(KeyCode::Up));
    expect($app->selectedIndex)->toBe(1);

    $app->handleEvent(CharKeyEvent::new('k'));
    expect($app->selectedIndex)->toBe(0);

    // End / Home...
    $app->handleEvent(CodedKeyEvent::new(KeyCode::End));
    expect($app->selectedIndex)->toBe(4);

    $app->handleEvent(CodedKeyEvent::new(KeyCode::Home));
    expect($app->selectedIndex)->toBe(0);
});

it('opens and closes help tab', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create([
        'status' => CommitStatus::Approved,
    ]);

    $app = new TuiApplication(collect([$commit]), $user);

    $app->handleEvent(CharKeyEvent::new('?'));

    expect($app->activeTab)->toBe(TuiTab::Help);

    $app->handleEvent(CodedKeyEvent::new(KeyCode::Esc));
    expect($app->activeTab)->toBe(TuiTab::Everyone);
});

it('composes text and handles backspace in composer tab', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create([
        'status' => CommitStatus::Approved,
    ]);

    $app = new TuiApplication(collect([$commit]), $user);
    $app->openComposer();

    expect($app->activeTab)->toBe(TuiTab::Compose)
        ->and($app->composeText)->toBe('');

    $app->handleEvent(CharKeyEvent::new('h'));
    $app->handleEvent(CharKeyEvent::new('i'));

    expect($app->composeText)->toBe('hi');

    $app->handleEvent(CodedKeyEvent::new(KeyCode::Backspace));
    expect($app->composeText)->toBe('h');

    // Canceling via Esc returns to feed...
    $app->handleEvent(CodedKeyEvent::new(KeyCode::Esc));
    expect($app->activeTab)->toBe(TuiTab::Everyone);
});

it('submits a new commit from the composer', function (): void {
    $user = User::factory()->create(['username' => 'terminaluser']);
    $commit = Commit::factory()->for($user)->create([
        'status' => CommitStatus::Approved,
    ]);

    $app = new TuiApplication(collect([$commit]), $user);
    $app->openComposer();
    $app->composeText = 'Posting directly from php-tui terminal!';

    $submitted = $app->submitCommit();

    expect($submitted)->toBeTrue()
        ->and($app->activeTab)->toBe(TuiTab::Everyone)
        ->and($app->statusMessage)->toContain('Committed');

    $this->assertDatabaseHas('commits', [
        'user_id' => $user->id,
        'body' => 'Posting directly from php-tui terminal!',
    ]);
});

it('stars and unstars commit with s key', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create([
        'status' => CommitStatus::Approved,
    ]);

    $app = new TuiApplication(collect([$commit]), $user);

    $app->toggleStar();

    expect($app->statusMessage)->toContain('Starred commit');
    $this->assertDatabaseHas('stars', [
        'user_id' => $user->id,
        'commit_id' => $commit->id,
    ]);

    $app->toggleStar();
    expect($app->statusMessage)->toContain('Unstarred commit');
    $this->assertDatabaseMissing('stars', [
        'user_id' => $user->id,
        'commit_id' => $commit->id,
    ]);
});

it('renders all tabs on a dummy backend without errors', function (): void {
    $user = User::factory()->create([
        'username' => 'renderdev',
        'name' => 'Render Dev',
    ]);
    $parent = Commit::factory()->for($user)->create([
        'body' => 'Top parent commit.',
        'status' => CommitStatus::Approved,
    ]);
    $reply = Commit::factory()->for($user)->create([
        'parent_id' => $parent->id,
        'body' => 'Child reply commit.',
        'status' => CommitStatus::Approved,
    ]);

    $backend = DummyBackend::fromDimensions(100, 24);
    $display = DisplayBuilder::default($backend)->build();

    $app = new TuiApplication(collect([$parent]), $user);

    // 1. Everyone tab
    $app->render($display);
    expect($backend->toString())->toContain('Everyone Feed')
        ->toContain('Top parent commit.');

    // 2. Compose tab
    $app->openComposer();
    $app->render($display);
    expect($backend->toString())->toContain('New Commit to /dev/null')
        ->toContain('280 characters');

    // 3. Thread tab
    $app->openThread();
    $app->render($display);
    expect($backend->toString())->toContain('Thread Conversation')
        ->toContain('Top parent commit.');

    // 4. Profile tab
    $app->activeTab = TuiTab::Profile;
    $app->render($display);
    expect($backend->toString())->toContain('Developer Profile')
        ->toContain('Unix Rank');

    // 5. Help tab
    $app->activeTab = TuiTab::Help;
    $app->render($display);

    expect($backend->toString())->toContain('Keybindings & Controls');
});

it('runs the interactive event loop and exits on quit key', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create([
        'status' => CommitStatus::Approved,
    ]);
    $app = new TuiApplication(collect([$commit]), $user);

    $terminal = PhpTermTerminal::new(
        painter: new StringPainter(),
        eventProvider: ArrayEventProvider::fromEvents(
            CharKeyEvent::new('q'),
        ),
        rawMode: new TestRawMode()
    );

    $backend = DummyBackend::fromDimensions(100, 24);
    $display = DisplayBuilder::default($backend)->build();

    $result = $app->run($display, $terminal, interactive: true);
    expect($result)->toBeTrue();
});

it('catches exceptions in run loop and returns false cleanly', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create([
        'status' => CommitStatus::Approved,
    ]);
    $app = new TuiApplication(collect([$commit]), $user);

    $backend = $this->createMock(Backend::class);
    $backend->method('size')->willReturn(Area::fromDimensions(100, 24));
    $backend->method('clearRegion')->willThrowException(new RuntimeException('Backend error'));

    $display = DisplayBuilder::default($backend)->build();

    $result = $app->run(display: $display);
    expect($result)->toBeFalse();
});

it('handles keyboard shortcuts in help tab', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create([
        'status' => CommitStatus::Approved,
    ]);
    $app = new TuiApplication(collect([$commit]), $user);
    $app->activeTab = TuiTab::Help;

    $app->handleEvent(CharKeyEvent::new('2'));

    expect($app->activeTab)->toBe(TuiTab::Following);

    $app->activeTab = TuiTab::Help;
    $app->handleEvent(CharKeyEvent::new('3'));

    expect($app->activeTab)->toBe(TuiTab::Thread);

    $app->activeTab = TuiTab::Help;
    $app->handleEvent(CharKeyEvent::new('4'));

    expect($app->activeTab)->toBe(TuiTab::Compose);

    $app->activeTab = TuiTab::Help;
    $app->handleEvent(CharKeyEvent::new('5'));

    expect($app->activeTab)->toBe(TuiTab::Profile);

    $app->activeTab = TuiTab::Help;
    $app->handleEvent(CharKeyEvent::new('?'));

    expect($app->activeTab)->toBe(TuiTab::Everyone);

    $app->activeTab = TuiTab::Help;
    $app->handleEvent(CharKeyEvent::new('q'));

    expect($app->activeTab)->toBe(TuiTab::Everyone);

    $app->activeTab = TuiTab::Help;
    $app->handleEvent(CharKeyEvent::new("\x1b"));

    expect($app->activeTab)->toBe(TuiTab::Everyone);

    $app->activeTab = TuiTab::Help;
    $app->handleEvent(CodedKeyEvent::new(KeyCode::Enter));

    expect($app->activeTab)->toBe(TuiTab::Everyone);

    $app->activeTab = TuiTab::Help;
    $app->handleEvent(CharKeyEvent::new('z'));

    expect($app->activeTab)->toBe(TuiTab::Help);
});

it('handles keyboard shortcuts in profile tab', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create([
        'status' => CommitStatus::Approved,
    ]);
    $app = new TuiApplication(collect([$commit]), $user);
    $app->activeTab = TuiTab::Profile;

    $app->handleEvent(CodedKeyEvent::new(KeyCode::Esc));

    expect($app->activeTab)->toBe(TuiTab::Everyone);

    $app->activeTab = TuiTab::Profile;
    $app->handleEvent(CharKeyEvent::new('q'));

    expect($app->activeTab)->toBe(TuiTab::Everyone);

    $app->activeTab = TuiTab::Profile;
    $app->handleEvent(CharKeyEvent::new("\x1b"));

    expect($app->activeTab)->toBe(TuiTab::Everyone);

    $app->activeTab = TuiTab::Profile;
    $app->handleEvent(CharKeyEvent::new('1'));

    expect($app->activeTab)->toBe(TuiTab::Everyone);

    $app->activeTab = TuiTab::Profile;
    $app->handleEvent(CharKeyEvent::new('2'));

    expect($app->activeTab)->toBe(TuiTab::Following);

    $app->activeTab = TuiTab::Profile;
    $app->handleEvent(CharKeyEvent::new('4'));

    expect($app->activeTab)->toBe(TuiTab::Compose);

    $app->activeTab = TuiTab::Profile;
    $app->handleEvent(CharKeyEvent::new('z'));

    expect($app->activeTab)->toBe(TuiTab::Profile);
});

it('navigates and interacts within thread view', function (): void {
    $user = User::factory()->create();
    $parent = Commit::factory()->for($user)->create([
        'body' => 'Parent',
        'status' => CommitStatus::Approved,
    ]);
    $reply1 = Commit::factory()->for($user)->create([
        'parent_id' => $parent->id,
        'body' => 'Reply 1',
        'status' => CommitStatus::Approved,
    ]);
    $reply2 = Commit::factory()->for($user)->create([
        'parent_id' => $parent->id,
        'body' => 'Reply 2',
        'status' => CommitStatus::Approved,
    ]);

    $app = new TuiApplication(collect([$reply1]), $user);
    $app->openThread();

    expect($app->activeTab)->toBe(TuiTab::Thread);

    // Navigate with Down / j
    $app->handleEvent(CodedKeyEvent::new(KeyCode::Down));
    $app->handleEvent(CharKeyEvent::new('j'));

    // Navigate with Up / k
    $app->handleEvent(CodedKeyEvent::new(KeyCode::Up));
    $app->handleEvent(CharKeyEvent::new('k'));

    // Star focused commit
    $app->handleEvent(CharKeyEvent::new('s'));

    expect($app->statusMessage)->toContain('Starred commit');

    // Reply to focused commit
    $app->handleEvent(CharKeyEvent::new('r'));
    expect($app->activeTab)->toBe(TuiTab::Compose);

    // Return to feed via Esc
    $app->activeTab = TuiTab::Thread;
    $app->handleEvent(CodedKeyEvent::new(KeyCode::Esc));

    expect($app->activeTab)->toBe(TuiTab::Everyone);

    // Return to feed via Backspace
    $app->activeTab = TuiTab::Thread;
    $app->handleEvent(CodedKeyEvent::new(KeyCode::Backspace));

    expect($app->activeTab)->toBe(TuiTab::Everyone);

    // Return to feed via q
    $app->activeTab = TuiTab::Thread;
    $app->handleEvent(CharKeyEvent::new('q'));

    expect($app->activeTab)->toBe(TuiTab::Everyone);

    // Return to feed via 1
    $app->activeTab = TuiTab::Thread;
    $app->handleEvent(CharKeyEvent::new('1'));

    expect($app->activeTab)->toBe(TuiTab::Everyone);
});

it('handles all global feed keyboard shortcuts', function (): void {
    $user = User::factory()->create();
    $commits = Commit::factory()->count(4)->for($user)->create([
        'status' => CommitStatus::Approved,
    ]);

    $app = new TuiApplication($commits, $user);

    $app->handleEvent(CharKeyEvent::new('g'));

    expect($app->selectedIndex)->toBe(0);

    $app->handleEvent(CharKeyEvent::new('G'));
    expect($app->selectedIndex)->toBe(3);

    $app->handleEvent(CharKeyEvent::new('3'));
    expect($app->activeTab)->toBe(TuiTab::Thread);
    $app->activeTab = TuiTab::Everyone;

    $app->handleEvent(CharKeyEvent::new('p'));

    expect($app->activeTab)->toBe(TuiTab::Compose);
    $app->activeTab = TuiTab::Everyone;

    $app->handleEvent(CharKeyEvent::new('r'));

    expect($app->activeTab)->toBe(TuiTab::Compose);
    $app->activeTab = TuiTab::Everyone;

    $app->handleEvent(CharKeyEvent::new('s'));

    expect($app->statusMessage)->toContain('Starred commit');

    $app->handleEvent(CharKeyEvent::new('u'));
    expect($app->activeTab)->toBe(TuiTab::Profile);
    $app->activeTab = TuiTab::Everyone;

    $app->handleEvent(CharKeyEvent::new('q'));

    expect($app->running)->toBeFalse();

    $app->running = true;
    $app->handleEvent(CodedKeyEvent::new(KeyCode::Esc));

    expect($app->running)->toBeFalse();

    $app->running = true;
    $app->handleEvent(CodedKeyEvent::new(KeyCode::Enter));

    expect($app->activeTab)->toBe(TuiTab::Thread);
    $app->activeTab = TuiTab::Everyone;

    $app->handleEvent(CodedKeyEvent::new(KeyCode::Tab));

    expect($app->activeTab)->toBe(TuiTab::Following);

    $app->handleEvent(CodedKeyEvent::new(KeyCode::BackTab));
    expect($app->activeTab)->toBe(TuiTab::Everyone);

    $app->handleEvent(CharKeyEvent::new('1'));
    expect($app->activeTab)->toBe(TuiTab::Everyone);

    $app->handleEvent(CodedKeyEvent::new(KeyCode::Insert));
    expect($app->activeTab)->toBe(TuiTab::Everyone);

    $app->handleEvent(CharKeyEvent::new('z'));
    expect($app->activeTab)->toBe(TuiTab::Everyone);
});

it('handles empty feed edge cases cleanly', function (): void {
    $user = User::factory()->create();
    $app = new TuiApplication(collect([]), $user);

    $app->moveSelection(1);
    expect($app->selectedIndex)->toBe(0)
        ->and($app->selectedCommit)->toBeNull();

    $app->jumpSelection(5);
    expect($app->selectedIndex)->toBe(0)
        ->and($app->selectedCommit)->toBeNull();

    $app->openThread();
    expect($app->activeTab)->toBe(TuiTab::Everyone);

    $app->openAuthorProfile(null);
    expect($app->activeTab)->toBe(TuiTab::Everyone);

    $app->toggleStar();
    expect($app->statusMessage)->toBeNull();
});

it('cycles tabs bidirectionally with wrap-around', function (): void {
    $user = User::factory()->create();
    $app = new TuiApplication(collect([]), $user);

    expect($app->activeTab)->toBe(TuiTab::Everyone);

    // Backward from index 0 wraps to Help
    $app->cycleTab(-1);
    expect($app->activeTab)->toBe(TuiTab::Help);

    // Forward from Help wraps to Everyone
    $app->cycleTab(1);
    expect($app->activeTab)->toBe(TuiTab::Everyone);
});

it('handles following feed and unauthenticated interactions', function (): void {
    $guestApp = new TuiApplication(collect([]));

    $guestApp->loadFollowingFeed();

    expect($guestApp->statusMessage)->toContain('Specify --user=@username');

    $guestApp->toggleStar(Commit::factory()->create());
    expect($guestApp->statusMessage)->toContain('Cannot star');

    $guestApp->openComposer();
    $guestApp->composeText = 'Valid commit message.';

    $submitted = $guestApp->submitCommit();
    expect($submitted)->toBeFalse()
        ->and($guestApp->statusMessage)->toContain('Cannot commit');

    // Short commit text validation...
    $user = User::factory()->create();
    $userApp = new TuiApplication(collect([]), $user);
    $userApp->openComposer();
    $userApp->composeText = 'hi';

    $submitted = $userApp->submitCommit();
    expect($submitted)->toBeFalse()
        ->and($userApp->statusMessage)->toContain('at least 3 characters');
});

it('handles compose tab special keys and length boundaries', function (): void {
    $user = User::factory()->create();
    $app = new TuiApplication(collect([]), $user);
    $app->openComposer();

    // Tab key inserts 2 spaces
    $app->handleEvent(CodedKeyEvent::new(KeyCode::Tab));

    expect($app->composeText)->toBe('  ');

    // Enter key submits
    $app->composeText = 'Posting from compose tab!';
    $app->handleEvent(CodedKeyEvent::new(KeyCode::Enter));

    expect($app->activeTab)->toBe(TuiTab::Everyone);

    // Max length boundary: ignore chars beyond 280
    $app->openComposer();
    $app->composeText = str_repeat('a', 280);
    $app->handleEvent(CharKeyEvent::new('b'));

    expect(mb_strlen($app->composeText))->toBe(280);
});

it('renders all edge cases and special states', function (): void {
    $backend = DummyBackend::fromDimensions(100, 24);
    $display = DisplayBuilder::default($backend)->build();

    // 1. Empty Everyone feed
    $emptyApp = new TuiApplication(collect([]));
    $emptyApp->render($display);
    expect($backend->toString())->toContain('No commits found in the log yet')
        ->toContain('Select a commit from the feed');

    // 2. Empty Following feed
    $emptyApp->activeTab = TuiTab::Following;
    $emptyApp->render($display);

    expect($backend->toString())->toContain('You are not following anyone');

    // 3. Verified SSH author in commit details
    $sshUser = User::factory()->create(['username' => 'sshdev']);
    SshKey::factory()->for($sshUser)->create();
    $commit1 = Commit::factory()->for($sshUser)->create(['status' => CommitStatus::Approved]);
    $commit2 = Commit::factory()->for($sshUser)->create(['status' => CommitStatus::Approved]);

    $sshApp = new TuiApplication(collect([$commit1, $commit2]), $sshUser);
    $sshApp->render($display);

    expect($backend->toString())->toContain('[VERIFIED SSH]');

    // 4. Empty thread view
    $sshApp->activeTab = TuiTab::Thread;
    $sshApp->render($display);

    expect($backend->toString())->toContain('No thread loaded');

    // 5. Compose replying to a commit
    $sshApp->openComposer($commit1);
    $sshApp->render($display);

    expect($backend->toString())->toContain('Reply to @sshdev');

    // 6. Profile view when user is null
    $nullUserApp = new TuiApplication(collect([]));
    $nullUserApp->activeTab = TuiTab::Profile;
    $nullUserApp->render($display);

    expect($backend->toString())->toContain('No user selected');

    // 7. Status bar with active message
    $sshApp->statusMessage = 'Active status test';
    $sshApp->render($display);

    expect($backend->toString())->toContain('Active status test');
});

it('safely handles non-integer model attributes in getIntAttribute', function (): void {
    $backend = DummyBackend::fromDimensions(100, 24);
    $display = DisplayBuilder::default($backend)->build();

    $user = User::factory()->create();
    $commit1 = Commit::factory()->for($user)->create([
        'status' => CommitStatus::Approved,
    ]);
    $commit1->setAttribute('stars_count', '42');
    $commit1->setAttribute('replies_count', false);

    $app = new TuiApplication(collect([$commit1]), $user);
    $app->render($display);

    expect($backend->toString())->toContain('42★');
});

it('maps Unix rank levels to terminal theme colors', function (int $level, AnsiColor $expectedColor): void {
    $app = new TuiApplication(collect([]));

    expect($app->rankColor($level))->toBe($expectedColor);
})->with([
    [1, AnsiColor::Gray],
    [2, AnsiColor::LightCyan],
    [3, AnsiColor::LightBlue],
    [4, AnsiColor::LightMagenta],
    [5, AnsiColor::LightYellow],
    [6, AnsiColor::LightRed],
    [0, AnsiColor::LightRed],
]);

it('builds interactive and headless terminal and display instances', function (): void {
    $app = new TuiApplication(collect([]));

    $headlessTerm = $app->buildTerminal(false);
    expect($headlessTerm)->toBeInstanceOf(PhpTermTerminal::class);

    $headlessDisplay = $app->buildDisplay($headlessTerm, false);
    expect($headlessDisplay)->toBeInstanceOf(Display::class);

    try {
        $interactiveTerm = $app->buildTerminal(true);
        expect($interactiveTerm)->toBeInstanceOf(PhpTermTerminal::class);

        $interactiveDisplay = $app->buildDisplay($interactiveTerm, true);
        expect($interactiveDisplay)->toBeInstanceOf(Display::class);
    } finally {
        if (defined('STDIN') && is_resource(STDIN)) {
            stream_set_blocking(STDIN, true);
        }
    }
});
