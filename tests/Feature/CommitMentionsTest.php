<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Jobs\ClassifyCommitTopics;
use App\Models\Commit;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('keeps the original mention recipient and profile after a rename and handle reuse', function (): void {
    Queue::fake([ClassifyCommitTopics::class]);
    config(['app.admins' => ['moderator']]);

    $author = User::factory()->create();
    $mentioned = User::factory()->create(['username' => 'original']);
    $moderator = User::factory()->create(['username' => 'moderator']);
    $body = 'Thanks @Original and @ORIGINAL!';

    $this->actingAs($author)->post('/commits', [
        'body' => $body,
        'mentions' => ['original' => $moderator->id],
    ])->assertRedirect();

    $commit = $author->commits()->sole();

    expect($commit->mentions)->toBe(['original' => $mentioned->id]);
    expect($mentioned->notifications()->count())->toBe(0);

    $mentioned->update(['username' => 'renamed']);
    $replacement = User::factory()->create(['username' => 'original']);

    $this->actingAs($moderator)->post(route('commit-approval.store', $commit))->assertRedirect();

    expect($mentioned->notifications()->sole()->type)->toBe(NotificationType::Mentioned);
    expect($replacement->notifications()->count())->toBe(0);
    expect($commit->refresh()->body)->toBe($body);

    $this->actingAs($author)->get(route('commit.show', [$author, $commit]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('commit.mentions.original', $mentioned->id)
            ->where('commit.body', $body));

    $this->get(route('mention.show', $mentioned->id))
        ->assertRedirect(route('user.show', ['user' => $mentioned]));

    $this->get(route('user.show', ['user' => $mentioned]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('user.id', $mentioned->id));
});

it('does not bind an unknown mention to an account created before approval', function (): void {
    Queue::fake([ClassifyCommitTopics::class]);
    config(['app.admins' => ['moderator']]);

    $author = User::factory()->create();
    $moderator = User::factory()->create(['username' => 'moderator']);

    $this->actingAs($author)->post('/commits', ['body' => 'Thanks @futuredev!'])->assertRedirect();

    $commit = $author->commits()->sole();
    $newcomer = User::factory()->create(['username' => 'futuredev']);

    $this->actingAs($moderator)->post(route('commit-approval.store', $commit))->assertRedirect();

    expect($commit->refresh()->mentions)->toBe([]);
    expect($newcomer->notifications()->count())->toBe(0);
});

it('only captures mentions in prose', function (): void {
    $author = User::factory()->create();
    $mentioned = User::factory()->create(['username' => 'original']);
    User::factory()->create(['username' => 'codeuser']);

    $this->actingAs($author)->post('/commits', [
        'body' => "Thanks @ORIGINAL, `@codeuser`\n```php\n@codeuser\n```\nmail@codeuser.com @codeuser/package",
    ])->assertRedirect();

    expect($author->commits()->sole()->mentions)->toBe(['original' => $mentioned->id]);
});

it('ignores deleted recipients at approval and returns 404 for their mention links', function (): void {
    Queue::fake([ClassifyCommitTopics::class]);
    config(['app.admins' => ['moderator']]);

    $author = User::factory()->create();
    $mentioned = User::factory()->create(['username' => 'original']);
    $moderator = User::factory()->create(['username' => 'moderator']);

    $this->actingAs($author)->post('/commits', ['body' => 'Thanks @original!'])->assertRedirect();

    $commit = $author->commits()->sole();
    $id = $mentioned->id;
    $mentioned->delete();
    $replacement = User::factory()->create(['username' => 'original']);

    $this->actingAs($moderator)->post(route('commit-approval.store', $commit))->assertRedirect();

    expect($replacement->notifications()->count())->toBe(0);

    $this->get('/mentions/'.$id)->assertNotFound();
});

it('redirects guests to the current profile and rejects invalid mention ids', function (): void {
    $mentioned = User::factory()->create(['username' => 'original']);

    $this->get('/mentions/'.$mentioned->id)->assertRedirect('/@original');
    $this->get('/mentions/original')->assertNotFound();
});

it('backfills legacy mentions without changing captured identities or commit text', function (): void {
    $mentioned = User::factory()->create(['username' => 'original']);
    User::factory()->create(['username' => 'codeuser']);
    $legacy = Commit::factory()->create([
        'body' => "Thanks @ORIGINAL! `@codeuser`\n```php\n@codeuser\n```\n@unknown @codeuser/package mail@codeuser.com",
        'mentions' => null,
    ]);
    $captured = Commit::factory()->create([
        'body' => 'Thanks @previous!',
        'mentions' => ['previous' => $mentioned->id],
    ]);
    $unresolved = Commit::factory()->create(['body' => 'Thanks @unknown!', 'mentions' => null]);
    $body = $legacy->body;
    $updatedAt = $legacy->updated_at;

    $migration = require database_path('migrations/2026_10_15_201954_backfill_commit_mentions.php');
    $migration->up();

    expect($legacy->refresh()->mentions)->toBe(['original' => $mentioned->id]);
    expect($legacy->body)->toBe($body);
    expect($legacy->updated_at->equalTo($updatedAt))->toBeTrue();
    expect($captured->refresh()->mentions)->toBe(['previous' => $mentioned->id]);
    expect($unresolved->refresh()->mentions)->toBe([]);
});

it('keeps captured mention identities through deletion and undo after a rename and handle reuse', function (?string $relationship): void {
    config(['app.admins' => ['moderator']]);

    $author = User::factory()->create(['username' => 'moderator']);
    $mentioned = User::factory()->create(['username' => 'original']);
    User::factory()->create(['username' => 'codeuser']);
    $body = "Thanks @ORIGINAL! `@codeuser`\n```php\n@codeuser\n```";
    $target = Commit::factory()->create();
    $payload = ['body' => $body];

    if ($relationship !== null) {
        $payload[$relationship] = $target->id;
    }

    $this->actingAs($author)->post(route('commit.store'), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();
    $commit = $author->commits()->sole();
    $segments = $commit->toArray()['body_segments'];

    expect($commit->mentions)->toBe(['original' => $mentioned->id])
        ->and($mentioned->notifications()->where('type', NotificationType::Mentioned)->count())->toBe(1);

    $mentioned->update(['username' => 'renamed']);
    $replacement = User::factory()->create(['username' => 'original']);

    $this->delete(route('commit.destroy', $commit))->assertRedirect();

    expect(Commit::withTrashed()->findOrFail($commit->id)->toArray())
        ->not->toHaveKeys(['body', 'body_segments', 'mentions']);

    $this->post(route('commit-restoration.store', $commit))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('restored.mentions.original', $mentioned->id)
        ->assertInertiaFlash('restored.body', $body)
        ->assertInertiaFlash('restored.body_segments', $segments);

    expect($commit->refresh()->mentions)->toBe(['original' => $mentioned->id])
        ->and($mentioned->notifications()->where('type', NotificationType::Mentioned)->count())->toBe(0)
        ->and($replacement->notifications()->count())->toBe(0);

    $this->get(route('commit.show', [$author, $commit]))
        ->assertInertia(fn ($page) => $page
            ->where('commit.mentions.original', $mentioned->id)
            ->where('commit.body_segments', $segments));
    $this->get(route('mention.show', $mentioned->id))
        ->assertRedirect(route('user.show', $mentioned));
})->with([
    'commit' => null,
    'reply' => 'parent_id',
    'quote' => 'quoted_commit_id',
]);
