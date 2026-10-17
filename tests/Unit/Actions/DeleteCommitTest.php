<?php

declare(strict_types=1);

use App\Actions\CreateCommitRestoration;
use App\Actions\DeleteCommit;
use App\Actions\DeleteCommitImages;
use App\Actions\UpdateCommitHotScores;
use App\Jobs\EraseDeletedCommitImages;
use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\CommitReport;
use App\Models\Notification;
use App\Services\TheAlgorithm;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('may delete a commit', function (): void {
    $commit = Commit::factory()->create();

    $action = resolve(DeleteCommit::class);

    $action->handle($commit);

    $this->assertSoftDeleted($commit);
});

it('keeps the replies of a deleted commit', function (): void {
    $commit = Commit::factory()->create();
    $reply = Commit::factory()->for($commit, 'parent')->create();

    resolve(DeleteCommit::class)->handle($commit);

    $this->assertNotSoftDeleted($reply);
});

it('keeps the quotes of a deleted commit', function (): void {
    $commit = Commit::factory()->create();
    $quote = Commit::factory()->for($commit, 'quotedCommit')->create();

    resolve(DeleteCommit::class)->handle($commit);

    $this->assertNotSoftDeleted($quote);
});

it('keeps the images of a deleted commit until the undo window closes', function (): void {
    Storage::fake();
    Queue::fake();

    $commit = Commit::factory()->create();
    $image = CommitImage::factory()->for($commit)->create();
    Storage::disk()->put($image->path, 'image');

    resolve(DeleteCommit::class)->handle($commit);

    $this->assertModelExists($image);
    Storage::disk()->assertExists($image->path);

    Queue::assertPushed(
        EraseDeletedCommitImages::class,
        fn (EraseDeletedCommitImages $job): bool => $job->commit->is($commit)
            && $job->delay instanceof DateTimeInterface
            && now()->addSeconds(30)->equalTo($job->delay),
    );
});

it('does not queue the erasure again for a commit already deleted', function (): void {
    $commit = Commit::factory()->create();
    resolve(DeleteCommit::class)->handle($commit);
    Queue::fake();

    resolve(DeleteCommit::class)->handle($commit);

    Queue::assertNothingPushed();
});

it('removes the reports on a deleted commit and keeps the reports on its replies and quotes', function (): void {
    $commit = Commit::factory()->create();
    CommitReport::factory()->for($commit)->create();
    $replyReport = CommitReport::factory()->for(Commit::factory()->for($commit, 'parent'))->create();
    $quoteReport = CommitReport::factory()->for(Commit::factory()->for($commit, 'quotedCommit'))->create();

    resolve(DeleteCommit::class)->handle($commit);

    expect(CommitReport::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$replyReport->id, $quoteReport->id])->sort()->values()->all());
});

it('lowers the thread when a reply is deleted', function (): void {
    $commit = Commit::factory()->create();
    $reply = Commit::factory()->for($commit, 'parent')->create();
    resolve(UpdateCommitHotScores::class)->handle([$commit->id]);

    resolve(DeleteCommit::class)->handle($reply);

    expect($commit->fresh()->hot_score)
        ->toEqualWithDelta(resolve(TheAlgorithm::class)->score($commit->created_at, 0, 0), 1e-9);
});

it('lowers the quoted thread when a quote is deleted', function (): void {
    $commit = Commit::factory()->create();
    $quote = Commit::factory()->for($commit, 'quotedCommit')->create();
    resolve(UpdateCommitHotScores::class)->handle([$commit->id]);

    resolve(DeleteCommit::class)->handle($quote);

    expect($commit->fresh()->hot_score)
        ->toEqualWithDelta(resolve(TheAlgorithm::class)->score($commit->created_at, 0, 0), 1e-9);
});

it('keeps report cleanup while applying the undo window to images', function (bool $undo): void {
    Storage::fake();
    Queue::fake();

    $commit = Commit::factory()->create();
    $report = CommitReport::factory()->for($commit)->create();
    $image = CommitImage::factory()->for($commit)->create();
    Storage::disk()->put($image->path, 'image');

    $deleted = resolve(DeleteCommit::class)->handle($commit);

    $this->assertModelMissing($report);
    $this->assertModelExists($image);
    Storage::disk()->assertExists($image->path);

    $this->travel(29)->seconds();

    if ($undo) {
        resolve(CreateCommitRestoration::class)->handle($deleted);
    }

    $this->travel(1)->seconds();
    resolve(DeleteCommitImages::class)->handle($deleted);

    $this->assertModelMissing($report);

    if ($undo) {
        $this->assertNotSoftDeleted($commit);
        $this->assertModelExists($image);
        Storage::disk()->assertExists($image->path);
    } else {
        $this->assertSoftDeleted($commit);
        $this->assertModelMissing($image);
        Storage::disk()->assertMissing($image->path);
    }
})->with([false, true]);

it('removes the notifications of a deleted commit and those about its replies and quotes, keeping the rest', function (): void {
    $commit = Commit::factory()->create();
    $starred = Notification::factory()->starred()->create(['commit_id' => $commit->id, 'subject_id' => $commit->id]);
    $replied = Notification::factory()->replied($commit)->create();
    $quoted = Notification::factory()->quoted()->create();
    $quoted->commit->quotedCommit()->associate($commit)->save();
    $mentionedInReply = Notification::factory()->mentioned()->create();
    $mentionedInReply->commit->parent()->associate($commit)->save();
    $unrelated = Notification::factory()->replied()->create();

    resolve(DeleteCommit::class)->handle($commit);

    expect(Notification::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$mentionedInReply->id, $unrelated->id])->sort()->values()->all())
        ->and($starred->fresh())->toBeNull()
        ->and($replied->fresh())->toBeNull()
        ->and($quoted->fresh())->toBeNull();
});
