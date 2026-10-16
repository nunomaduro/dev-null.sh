<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Enums\NotificationType;
use App\Jobs\ModerateCommit;
use App\Models\Commit;
use App\Models\LinkPreview;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;

beforeEach(function (): void {
    config(['app.admins' => ['admin']]);
});

it('renders pending commits oldest first', function (): void {
    $admin = User::factory()->create(['username' => 'admin']);

    Commit::factory()->pending()->create(['body' => 'Newer']);
    Commit::factory()->pending()->create(['body' => 'Older', 'created_at' => now()->subMinute()]);
    Commit::factory()->create();
    Commit::factory()->rejected()->create();

    $response = $this->actingAs($admin)
        ->get(route('moderation.show'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('moderation/show')
            ->has('commits.data', 2)
            ->where('commits.data.0.body', 'Older')
            ->where('commits.data.0.stars_count', 0)
            ->where('commits.data.0.starred', false)
            ->where('commits.data.1.body', 'Newer')
            ->has('commits.data.0.user'));
});

it('shows the ai decision on commits and their series parts', function (): void {
    $admin = User::factory()->create(['username' => 'admin']);
    $seriesId = Str::uuid7()->toString();

    $first = Commit::factory()->pending()->create([
        'series_id' => $seriesId,
        'series_position' => 1,
        'series_size' => 2,
        'ai_decision' => CommitStatus::Approved,
        'ai_categories' => [],
    ]);
    Commit::factory()->pending()->for($first, 'parent')->create([
        'series_id' => $seriesId,
        'series_position' => 2,
        'series_size' => 2,
        'ai_decision' => CommitStatus::Rejected,
        'ai_categories' => ['harassment'],
    ]);

    $response = $this->actingAs($admin)
        ->get(route('moderation.show'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('commits.data', 1)
            ->where('commits.data.0.ai_decision', 'approved')
            ->where('commits.data.0.ai_categories', [])
            ->where('commits.data.0.series_parts.1.ai_decision', 'rejected')
            ->where('commits.data.0.series_parts.1.ai_categories', ['harassment']));
});

it('keeps ai suggestions private and notifies the quoted author only after moderator approval', function (): void {
    config(['ai.providers.openai.key' => 'sk-test']);
    Classification::fake(fn (ClassificationPrompt $prompt): array => array_map(
        fn (string $key): BooleanAnswer => new BooleanAnswer($key === 'harassment' ? 0.9 : 0.0),
        array_combine(array_keys($prompt->questions), array_keys($prompt->questions)),
    ));

    $admin = User::factory()->create(['username' => 'admin']);
    $quoted = Commit::factory()->create([
        'ai_decision' => CommitStatus::Approved,
        'ai_categories' => ['private-quoted-category'],
    ]);
    $quote = Commit::factory()->pending()->for($quoted, 'quotedCommit')->create();

    dispatch_sync(new ModerateCommit($quote));

    expect($quote->refresh()->status)->toBe(CommitStatus::Pending)
        ->and($quoted->user->notifications()->exists())->toBeFalse();

    $this->actingAs($admin)->get(route('moderation.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commits.data.0.id', $quote->id)
            ->where('commits.data.0.ai_decision', 'rejected')
            ->where('commits.data.0.ai_categories', ['harassment'])
            ->where('commits.data.0.quoted_commit.id', $quoted->id)
            ->missing('commits.data.0.quoted_commit.ai_decision')
            ->missing('commits.data.0.quoted_commit.ai_categories'));

    $this->post(route('commit-approval.store', $quote))->assertRedirect();
    $this->post(route('commit-approval.store', $quote))->assertRedirect();

    $notification = $quoted->user->notifications()->sole();

    expect($notification->type)->toBe(NotificationType::Quoted)
        ->and($notification->actor_id)->toBe($quote->user_id)
        ->and($notification->commit_id)->toBe($quote->id)
        ->and($notification->subject_id)->toBeNull();

    auth()->logout();

    $this->get(route('commit.show', [$quote->user, $quote]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.id', $quote->id)
            ->missing('commit.ai_decision')
            ->missing('commit.ai_categories')
            ->missing('commit.quoted_commit.ai_decision')
            ->missing('commit.quoted_commit.ai_categories'))
        ->assertDontSee('private-quoted-category');
});

it('drops deleted pending commits from the queue', function (): void {
    $admin = User::factory()->create(['username' => 'admin']);

    Commit::factory()->pending()->create(['body' => 'Kept']);
    Commit::factory()->pending()->create()->delete();

    $response = $this->actingAs($admin)
        ->get(route('moderation.show'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('commits.data', 1)
            ->where('commits.data.0.body', 'Kept'));
});

it('may not be viewed by non admins', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('moderation.show'));

    $response->assertForbidden();
});

it('requires authentication', function (): void {
    $response = $this->get(route('moderation.show'));

    $response->assertRedirectToRoute('home');
});

it('shows who a pending reply is replying to', function (): void {
    $admin = User::factory()->create(['username' => 'admin']);
    $parent = Commit::factory()->for(User::factory()->create(['username' => 'octocat']))->create();
    Commit::factory()->pending()->for($parent, 'parent')->create();

    $response = $this->actingAs($admin)
        ->get(route('moderation.show'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commits.data.0.parent.user.username', 'octocat'));
});

it('shows what a pending quote is quoting', function (): void {
    $admin = User::factory()->create(['username' => 'admin']);
    $quoted = Commit::factory()->for(User::factory()->create(['username' => 'octocat']))->pending()->create(['body' => 'Quoted while pending']);
    Commit::factory()->pending()->for($quoted, 'quotedCommit')->create(['created_at' => now()->addMinute()]);

    $response = $this->actingAs($admin)
        ->get(route('moderation.show'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commits.data.1.quoted_commit.body', 'Quoted while pending')
            ->where('commits.data.1.quoted_commit.user.username', 'octocat'));
});

it('includes the link previews of pending commits', function (): void {
    $admin = User::factory()->create(['username' => 'admin']);

    $linkPreview = LinkPreview::factory()->fetched()->create(['title' => 'Pending link']);

    Commit::factory()->pending()->for($linkPreview)->create();

    $response = $this->actingAs($admin)
        ->get(route('moderation.show'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('commits.data', 1)
            ->where('commits.data.0.link_preview.title', 'Pending link'));
});

it('may approve many commits', function (): void {
    $admin = User::factory()->create(['username' => 'admin']);
    $commits = Commit::factory()->pending()->count(2)->create();
    $other = Commit::factory()->pending()->create();

    $response = $this->actingAs($admin)
        ->fromRoute('moderation.show')
        ->put(route('moderation.update'), [
            'commits' => $commits->modelKeys(),
        ]);

    $response->assertRedirectToRoute('moderation.show');

    foreach ($commits as $commit) {
        expect($commit->refresh()->status)->toBe(CommitStatus::Approved);
    }

    expect($other->refresh()->status)->toBe(CommitStatus::Pending);
});

it('only approves commits that are still pending', function (): void {
    $admin = User::factory()->create(['username' => 'admin']);
    $rejected = Commit::factory()->rejected()->create();

    $response = $this->actingAs($admin)
        ->fromRoute('moderation.show')
        ->put(route('moderation.update'), [
            'commits' => [$rejected->id],
        ]);

    $response->assertRedirectToRoute('moderation.show');

    expect($rejected->refresh()->status)->toBe(CommitStatus::Rejected);
});

it('requires valid commits', function (mixed $commits): void {
    $admin = User::factory()->create(['username' => 'admin']);

    $response = $this->actingAs($admin)
        ->fromRoute('moderation.show')
        ->put(route('moderation.update'), [
            'commits' => $commits,
        ]);

    $response->assertRedirectToRoute('moderation.show')
        ->assertSessionHasErrors('commits');
})->with([
    'missing' => [null],
    'empty' => [[]],
    'not an array' => ['not-an-array'],
    'too many' => [array_fill(0, 101, '0199a3b2-0000-7000-8000-000000000000')],
]);

it('may not approve many commits as a non admin', function (): void {
    $commit = Commit::factory()->pending()->create();

    $response = $this->actingAs($commit->user)
        ->put(route('moderation.update'), [
            'commits' => [$commit->id],
        ]);

    $response->assertForbidden();

    expect($commit->refresh()->status)->toBe(CommitStatus::Pending);
});
