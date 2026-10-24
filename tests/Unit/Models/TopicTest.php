<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\Topic;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('only serializes the label even when its commits are loaded', function (): void {
    $topic = Topic::factory()->hasAttached(Commit::factory())->create(['value' => 'Laravel']);

    expect($topic->fresh()->load('commits')->toArray())->toBe(['value' => 'Laravel']);
});

it('requires case insensitive unique labels', function (): void {
    Topic::factory()->create(['value' => 'Laravel']);

    Topic::factory()->create(['value' => 'LARAVEL']);
})->throws(UniqueConstraintViolationException::class);

it('requires unique commit topic pairs', function (): void {
    $topic = Topic::factory()->create();
    $commit = Commit::factory()->create();
    $commit->topics()->attach($topic);

    $commit->topics()->attach($topic);
})->throws(UniqueConstraintViolationException::class);

it('prunes only topics last applied strictly before one calendar month ago without deleting commits', function (): void {
    $this->travelTo(now()->setDate(2026, 3, 31)->setTime(12, 0));
    $commit = Commit::factory()->create(['topic_decision' => true]);
    $expired = Topic::factory()->create(['last_applied_at' => '2026-02-28 11:59:59']);
    $boundary = Topic::factory()->create(['last_applied_at' => '2026-02-28 12:00:00']);
    $recent = Topic::factory()->create(['last_applied_at' => '2026-03-01 12:00:00']);
    $commit->topics()->attach([$expired->id, $boundary->id, $recent->id]);

    $this->artisan('model:prune', ['--model' => [Topic::class]])->assertSuccessful();

    $this->assertModelMissing($expired);
    $this->assertModelExists($boundary);
    $this->assertModelExists($recent);
    $this->assertModelExists($commit);
    expect($commit->fresh()->topic_decision)->toBeTrue()
        ->and($commit->topics()->pluck('topics.id')->all())->toEqualCanonicalizing([$boundary->id, $recent->id]);
    $this->assertDatabaseMissing('commit_topic', ['topic_id' => $expired->id]);
});

it('keeps completion after every associated topic expires', function (): void {
    $commit = Commit::factory()->create(['topic_decision' => true]);
    $topic = Topic::factory()->create(['last_applied_at' => now()->subMonths(2)]);
    $commit->topics()->attach($topic);

    $this->artisan('model:prune', ['--model' => [Topic::class]])->assertSuccessful();

    expect($commit->fresh()->topics)->toBeEmpty()
        ->and($commit->fresh()->topic_decision)->toBeTrue();
});

it('cascades associations when a commit is permanently deleted without deleting shared topics', function (): void {
    $commit = Commit::factory()->create();
    $other = Commit::factory()->create();
    $topic = Topic::factory()->create();
    $topic->commits()->attach([$commit->id, $other->id]);

    $commit->forceDelete();

    $this->assertDatabaseMissing('commit_topic', ['commit_id' => $commit->id]);
    expect($topic->fresh()->commits->modelKeys())->toBe([$other->id]);
});

it('locks expiry candidates inside the limited pruning delete', function (): void {
    Topic::factory()->create(['last_applied_at' => now()->subMonths(2)]);
    DB::enableQueryLog();

    (new Topic)->pruneAll();

    $delete = collect(DB::getQueryLog())->first(fn (array $query): bool => str_starts_with($query['query'], 'delete'));
    DB::disableQueryLog();

    expect($delete['query'])->toContain('for update');
});
