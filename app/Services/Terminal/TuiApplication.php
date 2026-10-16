<?php

declare(strict_types=1);

namespace App\Services\Terminal;

use App\Actions\CreateCommit;
use App\Actions\CreateCommitStar;
use App\Actions\DeleteCommitStar;
use App\Enums\CommitStatus;
use App\Enums\TuiTab;
use App\Models\Commit;
use App\Models\User;
use App\Queries\CommitAncestorsQuery;
use App\Queries\CommitRepliesQuery;
use App\Queries\LogQueries\FollowingLogQuery;
use App\Queries\UserAchievementsQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PhpTui\Term\Event;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\EventProvider\ArrayEventProvider;
use PhpTui\Term\KeyCode;
use PhpTui\Term\Painter\StringPainter;
use PhpTui\Term\RawMode\TestRawMode;
use PhpTui\Term\Terminal as PhpTermTerminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Color\RgbColor;
use PhpTui\Tui\Display\Backend\DummyBackend;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\TabsWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;
use Throwable;

final class TuiApplication
{
    public TuiTab $activeTab = TuiTab::Everyone;

    public int $selectedIndex = 0;

    /**
     * @var Collection<int, Commit>|null
     */
    public ?Collection $followingCommits = null;

    public ?Commit $selectedCommit;

    public ?Commit $threadCommit = null;

    /**
     * @var Collection<int, Commit>
     */
    public Collection $threadAncestors;

    /**
     * @var Collection<int, Commit>
     */
    public Collection $threadReplies;

    public int $threadIndex = 0;

    public string $composeText = '';

    public ?Commit $composeReplyTo = null;

    public ?string $statusMessage = null;

    public bool $running = true;

    /**
     * @param  Collection<int, Commit>  $feedCommits
     */
    public function __construct(
        public Collection $feedCommits,
        public ?User $currentUser = null,
        public ?User $targetProfileUser = null,
        public string $title = 'everyone',
    ) {
        $this->selectedCommit = $this->feedCommits->first();
        $this->threadAncestors = new Collection();
        $this->threadReplies = new Collection();
    }

    /**
     * Starts the interactive TUI application loop.
     */
    public function run(?Display $display = null, ?PhpTermTerminal $terminal = null, bool $interactive = true): bool
    {
        try {
            $terminal ??= $this->buildTerminal($interactive);
            $display ??= $this->buildDisplay($terminal, $interactive);

            if (! $interactive) {
                $display->clear();
                $this->render($display);

                return true;
            }

            $terminal->enableRawMode();
            $display->clear();
            $this->render($display);

            while ($this->running) {
                $hasInput = false;

                while (($event = $terminal->events()->next()) instanceof Event) {
                    $this->handleEvent($event);
                    $hasInput = true;
                }

                if ($hasInput) {
                    $this->render($display);
                }

                Sleep::usleep(15_000);
            }

            $terminal->disableRawMode();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Dispatches keyboard input events.
     */
    public function handleEvent(Event $event): void
    {
        if ($this->activeTab === TuiTab::Compose) {
            $this->handleComposeEvent($event);

            return;
        }

        if ($this->activeTab === TuiTab::Help) {
            if ($event instanceof CodedKeyEvent && ($event->code === KeyCode::Esc || $event->code === KeyCode::Enter)) {
                $this->activeTab = TuiTab::Everyone;
            } elseif ($event instanceof CharKeyEvent) {
                match ($event->char) {
                    '1' => $this->switchTab(TuiTab::Everyone),
                    '2' => $this->switchTab(TuiTab::Following),
                    '3' => $this->switchTab(TuiTab::Thread),
                    '4' => $this->switchTab(TuiTab::Compose),
                    '5' => $this->switchTab(TuiTab::Profile),
                    '?', 'q', "\x1b" => $this->activeTab = TuiTab::Everyone,
                    default => null,
                };
            }

            return;
        }

        if ($this->activeTab === TuiTab::Profile) {
            if ($event instanceof CodedKeyEvent && $event->code === KeyCode::Esc) {
                $this->activeTab = TuiTab::Everyone;

                return;
            }

            if ($event instanceof CharKeyEvent) {
                if (in_array($event->char, ['q', "\x1b"], true)) {
                    $this->activeTab = TuiTab::Everyone;

                    return;
                }

                if ($event->char === '1') {
                    $this->switchTab(TuiTab::Everyone);

                    return;
                }

                if ($event->char === '2') {
                    $this->switchTab(TuiTab::Following);

                    return;
                }

                if ($event->char === '4') {
                    $this->switchTab(TuiTab::Compose);

                    return;
                }
            }
        }

        if ($this->activeTab === TuiTab::Thread) {
            $this->handleThreadEvent($event);

            return;
        }

        // Global feed navigation...
        if ($event instanceof CodedKeyEvent) {
            match ($event->code) {
                KeyCode::Up => $this->moveSelection(-1),
                KeyCode::Down => $this->moveSelection(1),
                KeyCode::Home => $this->jumpSelection(0),
                KeyCode::End => $this->jumpSelection(PHP_INT_MAX),
                KeyCode::Enter => $this->openThread(),
                KeyCode::Tab => $this->cycleTab(1),
                KeyCode::BackTab => $this->cycleTab(-1),
                KeyCode::Esc => $this->running = false,
                default => null,
            };

            return;
        }

        if ($event instanceof CharKeyEvent) {
            match ($event->char) {
                'k' => $this->moveSelection(-1),
                'j' => $this->moveSelection(1),
                'g' => $this->jumpSelection(0),
                'G' => $this->jumpSelection(PHP_INT_MAX),
                '1' => $this->switchTab(TuiTab::Everyone),
                '2' => $this->switchTab(TuiTab::Following),
                '3' => $this->switchTab(TuiTab::Thread),
                '4' => $this->switchTab(TuiTab::Compose),
                '5' => $this->switchTab(TuiTab::Profile),
                '?' => $this->switchTab(TuiTab::Help),
                'p' => $this->openComposer(),
                'r' => $this->openComposer($this->currentSelectedCommit()),
                's' => $this->toggleStar($this->currentSelectedCommit()),
                'u' => $this->openAuthorProfile($this->currentSelectedCommit()),
                'q' => $this->running = false,
                default => null,
            };
        }
    }

    /**
     * Moves current list selection.
     */
    public function moveSelection(int $delta): void
    {
        $commits = $this->activeFeedCommits();
        $total = $commits->count();

        if ($total === 0) {
            $this->selectedIndex = 0;
            $this->selectedCommit = null;

            return;
        }

        $this->selectedIndex = max(0, min($total - 1, $this->selectedIndex + $delta));
        $this->selectedCommit = $commits->get($this->selectedIndex);
    }

    /**
     * Jumps selection to the given index.
     */
    public function jumpSelection(int $index): void
    {
        $commits = $this->activeFeedCommits();
        $total = $commits->count();

        if ($total === 0) {
            $this->selectedIndex = 0;
            $this->selectedCommit = null;

            return;
        }

        $this->selectedIndex = max(0, min($total - 1, $index));
        $this->selectedCommit = $commits->get($this->selectedIndex);
    }

    /**
     * Returns the active feed's commits.
     *
     * @return Collection<int, Commit>
     */
    public function activeFeedCommits(): Collection
    {
        if ($this->activeTab === TuiTab::Following && $this->followingCommits instanceof Collection) {
            return $this->followingCommits;
        }

        return $this->feedCommits;
    }

    /**
     * Returns the currently selected commit in the active feed.
     */
    public function currentSelectedCommit(): ?Commit
    {
        return $this->selectedCommit ?? $this->activeFeedCommits()->first();
    }

    /**
     * Switches to the specified navigation tab.
     */
    public function switchTab(TuiTab $tab): void
    {
        $this->activeTab = $tab;

        if ($tab === TuiTab::Following && ! $this->followingCommits instanceof Collection) {
            $this->loadFollowingFeed();
        }

        if ($tab === TuiTab::Profile && ! $this->targetProfileUser instanceof User) {
            $this->targetProfileUser = $this->currentUser ?? $this->currentSelectedCommit()?->user;
        }

        $this->moveSelection(0);
    }

    /**
     * Cycles through navigation tabs.
     */
    public function cycleTab(int $direction): void
    {
        $cases = TuiTab::cases();
        $currentIndex = array_search($this->activeTab, $cases, true);
        $nextIndex = ($currentIndex !== false ? (int) $currentIndex : 0) + $direction;

        if ($nextIndex < 0) {
            $nextIndex = count($cases) - 1;
        } elseif ($nextIndex >= count($cases)) {
            $nextIndex = 0;
        }

        $this->switchTab($cases[$nextIndex]);
    }

    /**
     * Loads the following feed if the user is authenticated.
     */
    public function loadFollowingFeed(): void
    {
        if (! $this->currentUser instanceof User) {
            $this->followingCommits = new Collection();
            $this->statusMessage = 'Specify --user=@username to follow and view your following feed.';

            return;
        }

        /** @var Collection<int, Commit> $commits */
        $commits = resolve(FollowingLogQuery::class)
            ->builder($this->currentUser)
            ->limit(50)
            ->get();

        $this->followingCommits = $commits;
    }

    /**
     * Opens the conversation thread for the selected commit.
     */
    public function openThread(): void
    {
        $commit = $this->currentSelectedCommit();

        if (! $commit instanceof Commit) {
            return;
        }

        $this->threadCommit = $commit;
        $this->threadAncestors = resolve(CommitAncestorsQuery::class)->get($commit, $this->currentUser);

        $paginator = resolve(CommitRepliesQuery::class)->paginate($commit, $this->currentUser);
        $this->threadReplies = new Collection($paginator->items());

        $this->threadIndex = $this->threadAncestors->count();
        $this->activeTab = TuiTab::Thread;
    }

    /**
     * Opens composer for new commit or reply.
     */
    public function openComposer(?Commit $replyTo = null): void
    {
        $this->composeReplyTo = $replyTo;
        $this->composeText = '';
        $this->activeTab = TuiTab::Compose;
    }

    /**
     * Opens author profile in Profile view.
     */
    public function openAuthorProfile(?Commit $commit): void
    {
        if (! $commit instanceof Commit) {
            return;
        }

        $this->targetProfileUser = $commit->user;
        $this->activeTab = TuiTab::Profile;
    }

    /**
     * Stars or unstars a commit.
     */
    public function toggleStar(?Commit $commit = null): void
    {
        $commit ??= $this->currentSelectedCommit();

        if (! $commit instanceof Commit) {
            return;
        }

        if (! $this->currentUser instanceof User) {
            $this->statusMessage = 'Cannot star: specify --user=@username to interact.';

            return;
        }

        $isStarred = $this->currentUser->starredCommits()->where('commit_id', $commit->id)->exists();

        if ($isStarred) {
            resolve(DeleteCommitStar::class)->handle($this->currentUser, $commit);
            $commit->setAttribute('stars_count', max(0, $commit->stars_count - 1));
            $this->statusMessage = "Unstarred commit {$commit->id}.";
        } else {
            resolve(CreateCommitStar::class)->handle($this->currentUser, $commit);
            $commit->setAttribute('stars_count', $commit->stars_count + 1);
            $this->statusMessage = "Starred commit {$commit->id} ★!";
        }
    }

    /**
     * Submits the composer post to devnull.
     */
    public function submitCommit(): bool
    {
        $trimmed = mb_trim($this->composeText);

        if (mb_strlen($trimmed) < 3) {
            $this->statusMessage = 'Commit message must be at least 3 characters.';

            return false;
        }

        if (! $this->currentUser instanceof User) {
            $this->statusMessage = 'Cannot commit: specify --user=@username to post.';

            return false;
        }

        $created = resolve(CreateCommit::class)->handle(
            $this->currentUser,
            $this->composeText,
            $this->composeReplyTo
        );

        $created->load(['user']);

        $this->feedCommits->prepend($created);
        $this->selectedIndex = 0;
        $this->selectedCommit = $created;
        $this->composeText = '';
        $this->composeReplyTo = null;
        $this->activeTab = TuiTab::Everyone;

        $statusLabel = $created->status === CommitStatus::Approved ? 'Approved' : 'Pending review';
        $this->statusMessage = 'Committed ['.Str::substr($created->id, 0, 7)."] ({$statusLabel})!";

        return true;
    }

    /**
     * Returns flattened sequence of thread items (ancestors, focal commit, replies).
     *
     * @return Collection<int, Commit>
     */
    public function allThreadCommits(): Collection
    {
        $items = new Collection();

        foreach ($this->threadAncestors as $ancestor) {
            $items->push($ancestor);
        }

        if ($this->threadCommit instanceof Commit) {
            $items->push($this->threadCommit);
        }

        foreach ($this->threadReplies as $reply) {
            $items->push($reply);
        }

        return $items;
    }

    /**
     * Main display rendering method.
     */
    public function render(Display $display): void
    {
        $header = $this->renderHeader();
        $mainView = match ($this->activeTab) {
            TuiTab::Everyone, TuiTab::Following => $this->renderFeedView(),
            TuiTab::Thread => $this->renderThreadView(),
            TuiTab::Compose => $this->renderComposeView(),
            TuiTab::Profile => $this->renderProfileView(),
            TuiTab::Help => $this->renderHelpView(),
        };
        $footer = $this->renderFooter();

        $root = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::length(3),
                Constraint::min(10),
                Constraint::length(1)
            )
            ->widgets($header, $mainView, $footer);

        $display->draw($root);
    }

    /**
     * Maps unix rank level to its theme color.
     */
    public function rankColor(int $level): AnsiColor
    {
        return match ($level) {
            1 => AnsiColor::Gray,
            2 => AnsiColor::LightCyan,
            3 => AnsiColor::LightBlue,
            4 => AnsiColor::LightMagenta,
            5 => AnsiColor::LightYellow,
            default => AnsiColor::LightRed,
        };
    }

    /**
     * Builds terminal driver based on interactivity mode.
     */
    public function buildTerminal(bool $interactive): PhpTermTerminal
    {
        if ($interactive) {
            return PhpTermTerminal::new();
        }

        return PhpTermTerminal::new(
            painter: new StringPainter(),
            eventProvider: ArrayEventProvider::fromEvents(),
            rawMode: new TestRawMode()
        );
    }

    /**
     * Builds display backend based on interactivity mode.
     */
    public function buildDisplay(PhpTermTerminal $terminal, bool $interactive): Display
    {
        if ($interactive) {
            return DisplayBuilder::default(PhpTermBackend::new($terminal))->build();
        }

        return DisplayBuilder::default(DummyBackend::fromDimensions(100, 24))->build();
    }

    /**
     * Handles text buffer input in Compose mode.
     */
    private function handleComposeEvent(Event $event): void
    {
        if ($event instanceof CodedKeyEvent) {
            if ($event->code === KeyCode::Esc) {
                $this->composeText = '';
                $this->composeReplyTo = null;
                $this->activeTab = TuiTab::Everyone;
                $this->statusMessage = 'Draft cancelled.';

                return;
            }

            if ($event->code === KeyCode::Enter) {
                $this->submitCommit();

                return;
            }

            if ($event->code === KeyCode::Backspace) {
                $this->composeText = mb_substr($this->composeText, 0, -1);

                return;
            }

            if ($event->code === KeyCode::Tab) {
                if (mb_strlen($this->composeText) <= 278) {
                    $this->composeText .= '  ';
                }

                return;
            }
        }

        if ($event instanceof CharKeyEvent) {
            if (mb_strlen($this->composeText) < 280) {
                $this->composeText .= $event->char;
            }
        }
    }

    /**
     * Handles thread navigation and interaction.
     */
    private function handleThreadEvent(Event $event): void
    {
        $allThreadItems = $this->allThreadCommits();
        $total = $allThreadItems->count();

        if ($event instanceof CodedKeyEvent) {
            if ($event->code === KeyCode::Esc || $event->code === KeyCode::Backspace) {
                $this->activeTab = TuiTab::Everyone;

                return;
            }

            if ($event->code === KeyCode::Up) {
                $this->threadIndex = max(0, $this->threadIndex - 1);

                return;
            }

            if ($event->code === KeyCode::Down) {
                $this->threadIndex = min(max(0, $total - 1), $this->threadIndex + 1);

                return;
            }
        }

        if ($event instanceof CharKeyEvent) {
            if ($event->char === 'k') {
                $this->threadIndex = max(0, $this->threadIndex - 1);

                return;
            }

            if ($event->char === 'j') {
                $this->threadIndex = min(max(0, $total - 1), $this->threadIndex + 1);

                return;
            }

            $focused = $allThreadItems->get($this->threadIndex);

            if ($event->char === 'r' && $focused instanceof Commit) {
                $this->openComposer($focused);

                return;
            }

            if ($event->char === 's' && $focused instanceof Commit) {
                $this->toggleStar($focused);

                return;
            }

            if (in_array($event->char, ['q', '1'], true)) {
                $this->activeTab = TuiTab::Everyone;

                return;
            }
        }
    }

    /**
     * Renders header bar with tabs.
     */
    private function renderHeader(): Widget
    {
        $tabTitles = array_map(
            fn (TuiTab $tab): Line => Line::fromString($tab->label()),
            TuiTab::cases()
        );

        $tabIndex = (int) array_search($this->activeTab, TuiTab::cases(), true);

        $userBadge = $this->currentUser instanceof User
            ? " @{$this->currentUser->username} "
            : ' Guest ';

        return BlockWidget::default()
            ->borders(Borders::BOTTOM)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::DarkGray))
            ->titles(
                Title::fromString('/dev/null.sh'),
                Title::fromString($userBadge)
            )
            ->widget(
                TabsWidget::fromTitles(...$tabTitles)
                    ->select($tabIndex)
                    ->highlightStyle(
                        Style::default()
                            ->fg(AnsiColor::LightGreen)
                            ->addModifier(Modifier::BOLD)
                    )
            );
    }

    /**
     * Renders the split feed & inspector view.
     */
    private function renderFeedView(): Widget
    {
        $commits = $this->activeFeedCommits();
        $total = $commits->count();

        // Feed list lines...
        $listLines = [];

        if ($total === 0) {
            $listLines[] = Line::fromSpans(
                Span::styled(
                    $this->activeTab === TuiTab::Following
                        ? '  You are not following anyone with commits yet.'
                        : '  No commits found in the log yet.',
                    Style::default()->fg(AnsiColor::DarkGray)
                )
            );
        } else {
            foreach ($commits->values() as $index => $commit) {
                $isSelected = $index === $this->selectedIndex;
                $laneStyle = $this->laneStyle($commit->user?->lane() ?? 0);

                $hash = Str::substr($commit->id, 0, 7);
                $time = $commit->created_at->diffForHumans(short: true);
                $starsCount = $this->getIntAttribute($commit, 'stars_count');
                $stars = $starsCount > 0 ? " · {$starsCount}★" : '';
                $repliesCount = $this->getIntAttribute($commit, 'replies_count');
                $replies = $repliesCount > 0 ? " · {$repliesCount}⤿" : '';
                $username = TerminalRenderer::sanitize($commit->user->username ?? 'ghost');

                $indicator = $isSelected ? '▶ ' : '  ';
                $prefixStyle = $isSelected
                    ? Style::default()->fg(AnsiColor::LightGreen)->addModifier(Modifier::BOLD)
                    : Style::default()->fg(AnsiColor::DarkGray);

                $listLines[] = Line::fromSpans(
                    Span::styled($indicator, $prefixStyle),
                    Span::styled("* [{$hash}] ", Style::default()->fg($isSelected ? AnsiColor::White : AnsiColor::Gray)->addModifier(Modifier::BOLD)),
                    Span::styled("@{$username}", $laneStyle->addModifier(Modifier::BOLD)),
                    Span::styled(" · {$time}", Style::default()->fg(AnsiColor::DarkGray)),
                    Span::styled($stars, Style::default()->fg(AnsiColor::Yellow)->addModifier(Modifier::BOLD)),
                    Span::styled($replies, Style::default()->fg(AnsiColor::Cyan)),
                );

                // Body preview...
                $bodyPreview = Str::limit(str_replace(["\r", "\n"], ' ', TerminalRenderer::sanitize($commit->body)), 48);
                $listLines[] = Line::fromSpans(
                    Span::styled('    │ ', $laneStyle),
                    Span::styled(
                        $bodyPreview,
                        $isSelected
                            ? Style::default()->fg(AnsiColor::White)->addModifier(Modifier::BOLD)
                            : Style::default()->fg(AnsiColor::Gray)
                    ),
                );
            }
        }

        $listWidget = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Green))
            ->titles(Title::fromString($this->activeTab === TuiTab::Following ? ' Following Feed ' : ' Everyone Feed '))
            ->widget(ParagraphWidget::fromLines(...$listLines));

        // Inspector details pane...
        $selected = $this->currentSelectedCommit();
        $detailLines = [];

        if ($selected instanceof Commit) {
            $user = $selected->user;
            $laneStyle = $this->laneStyle($user?->lane() ?? 0);
            $username = TerminalRenderer::sanitize($user->username ?? 'ghost');
            $name = ($user !== null && $user->name !== '') ? ' ('.TerminalRenderer::sanitize($user->name).')' : '';
            $hash = Str::substr($selected->id, 0, 7);
            $date = $selected->created_at->toDayDateTimeString();

            $detailLines[] = Line::fromSpans(
                Span::styled("@{$username}", $laneStyle->addModifier(Modifier::BOLD)),
                Span::styled($name, Style::default()->fg(AnsiColor::DarkGray)),
                Span::styled(" [{$hash}]", Style::default()->fg(AnsiColor::White)->addModifier(Modifier::BOLD))
            );

            if ($user !== null && $user->sshKeys()->exists()) {
                $detailLines[] = Line::fromSpans(
                    Span::styled('🔒 [VERIFIED SSH]', Style::default()->fg(AnsiColor::LightGreen)->addModifier(Modifier::BOLD))
                );
            }

            $detailLines[] = Line::fromSpans(
                Span::styled("📅 Posted {$date}", Style::default()->fg(AnsiColor::DarkGray))
            );

            $detailLines[] = Line::fromString('');

            $wrapped = explode("\n", wordwrap(TerminalRenderer::sanitize($selected->body), 38, "\n", true));
            foreach ($wrapped as $line) {
                $detailLines[] = Line::fromSpans(
                    Span::styled('▌ ', $laneStyle),
                    Span::styled($line, Style::default()->fg(AnsiColor::White))
                );
            }

            $detailLines[] = Line::fromString('');
            $starsCount = $this->getIntAttribute($selected, 'stars_count');
            $repliesCount = $this->getIntAttribute($selected, 'replies_count');
            $detailLines[] = Line::fromSpans(
                Span::styled("★ {$starsCount} stars", Style::default()->fg(AnsiColor::Yellow)->addModifier(Modifier::BOLD)),
                Span::styled("   ⤿ {$repliesCount} replies", Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::BOLD))
            );

            $detailLines[] = Line::fromString('');
            $detailLines[] = Line::fromSpans(
                Span::styled('[Enter] ', Style::default()->fg(AnsiColor::LightGreen)->addModifier(Modifier::BOLD)),
                Span::styled('Thread  ', Style::default()->fg(AnsiColor::Gray)),
                Span::styled('[r] ', Style::default()->fg(AnsiColor::LightCyan)->addModifier(Modifier::BOLD)),
                Span::styled('Reply  ', Style::default()->fg(AnsiColor::Gray)),
                Span::styled('[s] ', Style::default()->fg(AnsiColor::LightYellow)->addModifier(Modifier::BOLD)),
                Span::styled('Star  ', Style::default()->fg(AnsiColor::Gray)),
                Span::styled('[u] ', Style::default()->fg(AnsiColor::LightMagenta)->addModifier(Modifier::BOLD)),
                Span::styled('Profile', Style::default()->fg(AnsiColor::Gray))
            );
        } else {
            $detailLines[] = Line::fromString('Select a commit from the feed on the left.');
        }

        $detailWidget = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
            ->titles(Title::fromString(' Commit Details '))
            ->widget(ParagraphWidget::fromLines(...$detailLines));

        return GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(Constraint::percentage(58), Constraint::percentage(42))
            ->widgets($listWidget, $detailWidget);
    }

    /**
     * Renders conversation thread tree.
     */
    private function renderThreadView(): Widget
    {
        $allThreadItems = $this->allThreadCommits();
        $threadLines = [];

        if ($allThreadItems->isEmpty()) {
            $threadLines[] = Line::fromString('No thread loaded. Select a commit from the feed and press [Enter].');
        } else {
            foreach ($allThreadItems->values() as $index => $commit) {
                $isSelected = $index === $this->threadIndex;
                $isFocal = $commit->id === $this->threadCommit?->id;
                $laneStyle = $this->laneStyle($commit->user?->lane() ?? 0);

                $hash = Str::substr($commit->id, 0, 7);
                $time = $commit->created_at->diffForHumans(short: true);
                $username = TerminalRenderer::sanitize($commit->user->username ?? 'ghost');

                $prefix = $isFocal ? '* (HEAD) ' : ($commit->parent_id !== null ? '├── ' : '• ');
                $indicator = $isSelected ? '▶ ' : '  ';

                $headStyle = $isFocal
                    ? Style::default()->fg(AnsiColor::LightYellow)->addModifier(Modifier::BOLD)
                    : Style::default()->fg(AnsiColor::DarkGray);

                $starsCount = $this->getIntAttribute($commit, 'stars_count');
                $starsText = $starsCount > 0 ? " · {$starsCount}★" : '';

                $threadLines[] = Line::fromSpans(
                    Span::styled($indicator, Style::default()->fg(AnsiColor::LightGreen)->addModifier(Modifier::BOLD)),
                    Span::styled($prefix, $headStyle),
                    Span::styled("[{$hash}] ", Style::default()->fg(AnsiColor::White)->addModifier(Modifier::BOLD)),
                    Span::styled("@{$username}", $laneStyle->addModifier(Modifier::BOLD)),
                    Span::styled(" · {$time}", Style::default()->fg(AnsiColor::DarkGray)),
                    Span::styled($starsText, Style::default()->fg(AnsiColor::Yellow)->addModifier(Modifier::BOLD)),
                );

                $bodyLine = Str::limit(str_replace(["\r", "\n"], ' ', TerminalRenderer::sanitize($commit->body)), 70);
                $threadLines[] = Line::fromSpans(
                    Span::styled('    │ ', $laneStyle),
                    Span::styled($bodyLine, $isSelected ? Style::default()->fg(AnsiColor::White)->addModifier(Modifier::BOLD) : Style::default()->fg(AnsiColor::Gray))
                );
            }
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::LightMagenta))
            ->titles(
                Title::fromString(' Thread Conversation '),
                Title::fromString(' [Esc] Back to Feed  ·  [r] Reply  ·  [s] Star ')
            )
            ->widget(ParagraphWidget::fromLines(...$threadLines));
    }

    /**
     * Renders interactive commit/reply composer.
     */
    private function renderComposeView(): Widget
    {
        $replyUsername = $this->composeReplyTo instanceof Commit
            ? TerminalRenderer::sanitize($this->composeReplyTo->user->username ?? 'ghost')
            : '';

        $title = $this->composeReplyTo instanceof Commit
            ? " Reply to @{$replyUsername} (".Str::substr($this->composeReplyTo->id, 0, 7).') '
            : ' New Commit to /dev/null ';

        $length = mb_strlen($this->composeText);
        $counterColor = match (true) {
            $length > 270 => AnsiColor::LightRed,
            $length > 220 => AnsiColor::LightYellow,
            default => AnsiColor::LightGreen,
        };

        $lines = [
            Line::fromSpans(
                Span::styled('Pipe your thoughts directly to /dev/null:', Style::default()->fg(AnsiColor::LightCyan)->addModifier(Modifier::BOLD))
            ),
            Line::fromString(''),
            Line::fromSpans(
                Span::styled($this->composeText, Style::default()->fg(AnsiColor::White)),
                Span::styled('█', Style::default()->fg(AnsiColor::LightGreen)->addModifier(Modifier::BOLD))
            ),
            Line::fromString(''),
            Line::fromSpans(
                Span::styled(str_repeat('─', 60), Style::default()->fg(AnsiColor::DarkGray))
            ),
            Line::fromSpans(
                Span::styled("[ {$length} / 280 characters ] ", Style::default()->fg($counterColor)->addModifier(Modifier::BOLD)),
                Span::styled('[Enter] ', Style::default()->fg(AnsiColor::LightGreen)->addModifier(Modifier::BOLD)),
                Span::styled('Commit to devnull  ·  ', Style::default()->fg(AnsiColor::DarkGray)),
                Span::styled('[Esc] ', Style::default()->fg(AnsiColor::LightRed)->addModifier(Modifier::BOLD)),
                Span::styled('Cancel', Style::default()->fg(AnsiColor::DarkGray))
            ),
        ];

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::LightGreen))
            ->titles(Title::fromString($title))
            ->widget(ParagraphWidget::fromLines(...$lines));
    }

    /**
     * Renders developer profile, Unix ranks, streak and badges.
     */
    private function renderProfileView(): Widget
    {
        $user = $this->targetProfileUser ?? $this->currentUser ?? $this->currentSelectedCommit()?->user;

        if ($user === null) {
            return BlockWidget::default()
                ->borders(Borders::ALL)
                ->borderType(BorderType::Rounded)
                ->titles(Title::fromString(' Profile '))
                ->widget(ParagraphWidget::fromString('No user selected.'));
        }

        $achievements = resolve(UserAchievementsQuery::class)->get($user, $this->currentUser);

        $laneStyle = $this->laneStyle($user->lane());
        $username = TerminalRenderer::sanitize($user->username);
        $name = $user->name ? ' ('.TerminalRenderer::sanitize($user->name).')' : '';

        $lines = [];
        $lines[] = Line::fromSpans(
            Span::styled("@{$username}", $laneStyle->addModifier(Modifier::BOLD)),
            Span::styled($name, Style::default()->fg(AnsiColor::DarkGray)),
            Span::styled(" · UID {$user->id}", Style::default()->fg(AnsiColor::White)->addModifier(Modifier::BOLD))
        );

        if ($user->bio) {
            $lines[] = Line::fromSpans(
                Span::styled(TerminalRenderer::sanitize($user->bio), Style::default()->fg(AnsiColor::Gray))
            );
        }

        $lines[] = Line::fromString(str_repeat('─', 70));

        // Rank and XP...
        $rankTitle = ucfirst((string) $achievements['rank']);
        $level = $achievements['level'];
        $xp = $achievements['xp'];
        $nextXp = $achievements['next_level_xp'];
        $progress = $achievements['level_progress'];

        $rankColor = $this->rankColor($level);

        $barFilled = (int) round($progress / 5);
        $filledBlocks = str_repeat('█', $barFilled);
        $emptyBlocks = str_repeat('░', 20 - $barFilled);

        $lines[] = Line::fromSpans(
            Span::styled("Unix Rank: {$rankTitle} [Level {$level}]", Style::default()->fg($rankColor)->addModifier(Modifier::BOLD)),
            Span::styled('  ·  ', Style::default()->fg(AnsiColor::DarkGray)),
            Span::styled("{$xp} Total XP", Style::default()->fg(AnsiColor::Yellow)->addModifier(Modifier::BOLD))
        );

        $nextText = $nextXp !== null ? "Next Rank at {$nextXp} XP" : 'Max Rank Reached';
        $lines[] = Line::fromSpans(
            Span::styled('Progress: [', Style::default()->fg(AnsiColor::DarkGray)),
            Span::styled($filledBlocks, Style::default()->fg($rankColor)->addModifier(Modifier::BOLD)),
            Span::styled($emptyBlocks, Style::default()->fg(AnsiColor::DarkGray)),
            Span::styled("] {$progress}%  ({$nextText})", Style::default()->fg(AnsiColor::DarkGray))
        );

        $lines[] = Line::fromString('');

        // Streak & Breakdown...
        $streakCount = $achievements['xp_breakdown']['streak']['count'];
        $commitsCount = $achievements['xp_breakdown']['commits']['count'];
        $starsRecv = $achievements['xp_breakdown']['stars_received']['count'];
        $repliesCount = $achievements['xp_breakdown']['replies']['count'];
        $starsGiven = $achievements['xp_breakdown']['stars_given']['count'];

        $lines[] = Line::fromSpans(
            Span::styled("🔥 {$streakCount}-Day Streak  ·  ", Style::default()->fg(AnsiColor::LightYellow)->addModifier(Modifier::BOLD)),
            Span::styled("{$commitsCount} Commits  ·  ", Style::default()->fg(AnsiColor::White)),
            Span::styled("★ {$starsRecv} Stars Recv  ·  ", Style::default()->fg(AnsiColor::Yellow)),
            Span::styled("⤿ {$repliesCount} Replies  ·  ", Style::default()->fg(AnsiColor::Cyan)),
            Span::styled("{$starsGiven} Stars Given", Style::default()->fg(AnsiColor::DarkGray))
        );

        $lines[] = Line::fromString('');
        $lines[] = Line::fromSpans(
            Span::styled('Unlocked Achievements & Badges:', Style::default()->fg(AnsiColor::White)->addModifier(Modifier::BOLD))
        );

        foreach (array_slice($achievements['badges'], 0, 6) as $badge) {
            $isUnlocked = $badge['unlocked'];
            $check = $isUnlocked ? '[✓]' : '[ ]';
            $badgeStyle = $isUnlocked
                ? Style::default()->fg(AnsiColor::LightGreen)->addModifier(Modifier::BOLD)
                : Style::default()->fg(AnsiColor::DarkGray);

            $lines[] = Line::fromSpans(
                Span::styled("  {$check} ", $badgeStyle),
                Span::styled("{$badge['name']}: ", $isUnlocked ? Style::default()->fg(AnsiColor::LightCyan)->addModifier(Modifier::BOLD) : Style::default()->fg(AnsiColor::DarkGray)),
                Span::styled((string) $badge['description'], Style::default()->fg(AnsiColor::Gray))
            );
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg($rankColor))
            ->titles(
                Title::fromString(" Developer Profile: @{$username} "),
                Title::fromString(' [Esc] Back to Feed ')
            )
            ->widget(ParagraphWidget::fromLines(...$lines));
    }

    /**
     * Renders help modal and keybinding cheatsheet.
     */
    private function renderHelpView(): Widget
    {
        $lines = [
            Line::fromSpans(
                Span::styled('/dev/null.sh Terminal Client - Keybindings & Controls', Style::default()->fg(AnsiColor::LightCyan)->addModifier(Modifier::BOLD))
            ),
            Line::fromString(str_repeat('─', 70)),
            Line::fromSpans(Span::styled('  Navigation:', Style::default()->fg(AnsiColor::LightGreen)->addModifier(Modifier::BOLD))),
            Line::fromString('    ↑ / k          Move selection up'),
            Line::fromString('    ↓ / j          Move selection down'),
            Line::fromString('    Home / g       Jump to top of feed'),
            Line::fromString('    End / G        Jump to bottom of feed'),
            Line::fromString('    Enter          Open full thread conversation for selected commit'),
            Line::fromString(''),
            Line::fromSpans(Span::styled('  Interactions:', Style::default()->fg(AnsiColor::LightGreen)->addModifier(Modifier::BOLD))),
            Line::fromString('    p              Create a new commit to /dev/null'),
            Line::fromString('    r              Reply to the selected commit'),
            Line::fromString('    s              Star or unstar the selected commit'),
            Line::fromString('    u              Inspect author profile, Unix rank & achievements'),
            Line::fromString(''),
            Line::fromSpans(Span::styled('  Views & Tabs:', Style::default()->fg(AnsiColor::LightGreen)->addModifier(Modifier::BOLD))),
            Line::fromString('    1              Everyone feed (global timeline)'),
            Line::fromString('    2              Following feed'),
            Line::fromString('    3              Thread conversation view'),
            Line::fromString('    4              Composer (New Post / Reply)'),
            Line::fromString('    5              Developer profile & ranks'),
            Line::fromString('    ?              Toggle this help cheatsheet'),
            Line::fromString('    q / Esc        Back / Quit application'),
        ];

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::LightYellow))
            ->titles(Title::fromString(' Help / Cheatsheet '), Title::fromString(' [Esc] Close Help '))
            ->widget(ParagraphWidget::fromLines(...$lines));
    }

    /**
     * Renders bottom status bar.
     */
    private function renderFooter(): Widget
    {
        $status = $this->statusMessage
            ? Line::fromSpans(
                Span::styled('  ⚡ ', Style::default()->fg(AnsiColor::LightYellow)->addModifier(Modifier::BOLD)),
                Span::styled($this->statusMessage, Style::default()->fg(AnsiColor::White)->addModifier(Modifier::BOLD))
            )
            : Line::fromSpans(
                Span::styled('  ↑/↓ ', Style::default()->fg(AnsiColor::Green)->addModifier(Modifier::BOLD)),
                Span::styled('Nav · ', Style::default()->fg(AnsiColor::DarkGray)),
                Span::styled('Enter ', Style::default()->fg(AnsiColor::Green)->addModifier(Modifier::BOLD)),
                Span::styled('Thread · ', Style::default()->fg(AnsiColor::DarkGray)),
                Span::styled('p ', Style::default()->fg(AnsiColor::Green)->addModifier(Modifier::BOLD)),
                Span::styled('Post · ', Style::default()->fg(AnsiColor::DarkGray)),
                Span::styled('r ', Style::default()->fg(AnsiColor::Green)->addModifier(Modifier::BOLD)),
                Span::styled('Reply · ', Style::default()->fg(AnsiColor::DarkGray)),
                Span::styled('s ', Style::default()->fg(AnsiColor::Green)->addModifier(Modifier::BOLD)),
                Span::styled('Star · ', Style::default()->fg(AnsiColor::DarkGray)),
                Span::styled('1-5 ', Style::default()->fg(AnsiColor::Green)->addModifier(Modifier::BOLD)),
                Span::styled('Tabs · ', Style::default()->fg(AnsiColor::DarkGray)),
                Span::styled('q ', Style::default()->fg(AnsiColor::Green)->addModifier(Modifier::BOLD)),
                Span::styled('Quit', Style::default()->fg(AnsiColor::DarkGray))
            );

        return ParagraphWidget::fromLines($status);
    }

    /**
     * Resolves the RgbColor style for a given user lane.
     */
    private function laneStyle(int $laneIndex): Style
    {
        $rgb = TerminalRenderer::LANES[$laneIndex] ?? TerminalRenderer::LANES[0];

        return Style::default()->fg(RgbColor::fromRgb($rgb[0], $rgb[1], $rgb[2]));
    }

    /**
     * Safely reads an integer attribute from an Eloquent model without triggering strict mode.
     */
    private function getIntAttribute(Commit $commit, string $key): int
    {
        $value = $commit->getAttributes()[$key] ?? 0;

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}
