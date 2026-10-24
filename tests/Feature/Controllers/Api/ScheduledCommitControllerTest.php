<?php

declare(strict_types=1);

use App\Actions\UpdateScheduledCommitSubmittedAt;
use App\Enums\CommitStatus;
use App\Enums\ModerationReason;
use App\Models\Commit;
use App\Models\ScheduledCommit;
use App\Models\User;
use App\Models\UserTimeout;
use App\Services\CommitBodies;

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
    $this->user = User::factory()->create();
    $this->keyPair = apiKeyPairFor($this->user);
});

it('lists the waiting scheduled commits first, then the submitted ones, each by when they were due', function (): void {
    $submitted = ScheduledCommit::factory()->for($this->user)->create(['scheduled_at' => now()->subHour()]);
    resolve(UpdateScheduledCommitSubmittedAt::class)->handle($submitted->id);
    $later = ScheduledCommit::factory()->for($this->user)->create(['scheduled_at' => now()->addDays(2)]);
    $sooner = ScheduledCommit::factory()->for($this->user)->create([
        'body' => 'Shipped the parser benchmark.',
        'poll' => ['options' => ['Tabs', 'Spaces'], 'duration' => 1440],
        'scheduled_at' => now()->addDay(),
    ]);
    ScheduledCommit::factory()->for($this->user)->create(['canceled_at' => now(), 'body' => null]);
    ScheduledCommit::factory()->create();

    $response = signedApiRequest($this->keyPair, 'GET', route('api-scheduled-commit.index'));

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0', [
            'id' => $sooner->id,
            'body' => 'Shipped the parser benchmark.',
            ...resolve(CommitBodies::class)->segments('Shipped the parser benchmark.'),
            'poll_options' => ['Tabs', 'Spaces'],
            'image_count' => 0,
            'scheduled_at' => '2026-10-16T12:00:00Z',
            'submitted_at' => null,
            'status' => 'scheduled',
            'commit_id' => null,
        ])
        ->assertJsonPath('data.1.id', $later->id)
        ->assertJsonPath('data.2', [
            'id' => $submitted->id,
            'body' => null,
            'body_segments' => [],
            'preview_segments' => null,
            'poll_options' => null,
            'image_count' => 0,
            'scheduled_at' => '2026-10-15T11:00:00Z',
            'submitted_at' => '2026-10-15T12:00:00Z',
            'status' => 'pending',
            'commit_id' => Commit::query()->sole()->id,
        ]);
});

it('says what became of the submitted ones', function (?CommitStatus $status): void {
    $scheduled = ScheduledCommit::factory()->for($this->user)->create(['scheduled_at' => now()]);
    resolve(UpdateScheduledCommitSubmittedAt::class)->handle($scheduled->id);
    $commit = Commit::query()->sole();

    if ($status instanceof CommitStatus) {
        $commit->update(['status' => $status]);
    } else {
        $commit->delete();
    }

    signedApiRequest($this->keyPair, 'GET', route('api-scheduled-commit.index'))
        ->assertOk()
        ->assertJsonPath('data.0.status', $status->value ?? 'deleted')
        ->assertJsonPath('data.0.commit_id', $status instanceof CommitStatus ? $commit->id : null)
        ->assertJsonPath('data.0.body', null);
})->with([CommitStatus::Approved, CommitStatus::Rejected, null]);

it('lists 20 a page', function (): void {
    ScheduledCommit::factory()->for($this->user)->count(21)->create(['submitted_at' => now()->subDay(), 'body' => null]);

    signedApiRequest($this->keyPair, 'GET', route('api-scheduled-commit.index'))
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('links.next', route('api-scheduled-commit.index', ['page' => 2]));
});

it('reschedules a waiting commit with a new body, and answers with it', function (): void {
    $scheduled = ScheduledCommit::factory()->for($this->user)->create([
        'body' => 'Shipped https://example.com/old',
        'link_preview_url' => 'https://example.com/old',
        'poll' => ['options' => ['Tabs', 'Spaces'], 'duration' => 1440],
    ]);

    $response = signedApiRequest($this->keyPair, 'PUT', route('api-scheduled-commit.update', $scheduled), [
        'body' => '  Shipped https://example.com/new  ',
        'scheduled_at' => '2026-10-17T10:30:00+05:30',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.id', $scheduled->id)
        ->assertJsonPath('data.body', 'Shipped https://example.com/new')
        ->assertJsonPath('data.poll_options', ['Tabs', 'Spaces'])
        ->assertJsonPath('data.scheduled_at', '2026-10-17T05:00:00Z')
        ->assertJsonPath('data.status', 'scheduled');

    expect($scheduled->refresh())
        ->body->toBe('Shipped https://example.com/new')
        ->link_preview_url->toBe('https://example.com/new')
        ->and($scheduled->scheduled_at->toIso8601String())->toBe('2026-10-17T05:00:00+00:00');
});

it('may reschedule one that is due and still waiting for the scheduler', function (): void {
    $scheduled = ScheduledCommit::factory()->for($this->user)->create(['scheduled_at' => now()->subMinute()]);

    signedApiRequest($this->keyPair, 'PUT', route('api-scheduled-commit.update', $scheduled), [
        'body' => 'Not quite yet.',
        'scheduled_at' => '2026-10-15T18:00:00+00:00',
    ])->assertOk();

    $this->artisan('commits:submit-scheduled')->assertSuccessful();

    expect(Commit::query()->count())->toBe(0);
});

it('lets one with images keep a short body', function (): void {
    $scheduled = ScheduledCommit::factory()->for($this->user)->create([
        'images' => [['path' => 'commit-images/a.webp', 'width' => 10, 'height' => 10, 'description' => null, 'position' => 0]],
    ]);

    signedApiRequest($this->keyPair, 'PUT', route('api-scheduled-commit.update', $scheduled), [
        'body' => '',
        'scheduled_at' => '2026-10-16T12:00:00+00:00',
    ])->assertOk()
        ->assertJsonPath('data.image_count', 1);

    expect($scheduled->refresh()->body)->toBe('');
});

it('rejects invalid times and bodies, as when scheduling', function (array $payload, string $field): void {
    $scheduled = ScheduledCommit::factory()->for($this->user)->create(['body' => 'Shipped a scheduling feature.']);

    signedApiRequest($this->keyPair, 'PUT', route('api-scheduled-commit.update', $scheduled), [
        'body' => 'Shipped a better scheduling feature.',
        'scheduled_at' => '2026-10-16T12:00:00+00:00',
        ...$payload,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect($scheduled->refresh()->body)->toBe('Shipped a scheduling feature.');
})->with([
    'missing time' => [['scheduled_at' => null], 'scheduled_at'],
    'missing offset' => [['scheduled_at' => '2026-10-16T12:00:00'], 'scheduled_at'],
    'past' => [['scheduled_at' => '2026-10-15T11:59:00+00:00'], 'scheduled_at'],
    'beyond thirty days' => [['scheduled_at' => '2026-11-14T12:00:01+00:00'], 'scheduled_at'],
    'too long' => [['body' => str_repeat('a', 4001)], 'body'],
]);

it('does not let a user in timeout change what is waiting, but lets them cancel it', function (): void {
    $scheduled = ScheduledCommit::factory()->for($this->user)->create(['body' => 'Shipped a scheduling feature.']);
    UserTimeout::factory()->for($this->user)->create(['reason' => ModerationReason::Spam, 'ends_at' => now()->addDays(2)]);

    signedApiRequest($this->keyPair, 'PUT', route('api-scheduled-commit.update', $scheduled), [
        'body' => 'Something else entirely.',
        'scheduled_at' => '2026-10-16T12:00:00+00:00',
    ])->assertForbidden()
        ->assertJsonPath('code', 'timed_out');

    expect($scheduled->refresh()->body)->toBe('Shipped a scheduling feature.');

    signedApiRequest($this->keyPair, 'DELETE', route('api-scheduled-commit.destroy', $scheduled))->assertNoContent();

    expect($scheduled->refresh()->canceled_at)->not->toBeNull();
});

it('cancels a waiting commit, so it is never submitted', function (): void {
    $scheduled = ScheduledCommit::factory()->for($this->user)->create(['scheduled_at' => now()]);

    signedApiRequest($this->keyPair, 'DELETE', route('api-scheduled-commit.destroy', $scheduled))->assertNoContent();

    $this->artisan('commits:submit-scheduled')->assertSuccessful();

    expect($scheduled->refresh())
        ->canceled_at->not->toBeNull()
        ->body->toBeNull()
        ->and(Commit::query()->count())->toBe(0);
});

it('changes nothing once it was submitted or canceled', function (string $method, string $route, string $state): void {
    $scheduled = ScheduledCommit::factory()->for($this->user)->create(['scheduled_at' => now()]);

    if ($state === 'submitted') {
        resolve(UpdateScheduledCommitSubmittedAt::class)->handle($scheduled->id);
    } else {
        $scheduled->update(['canceled_at' => now()]);
    }

    signedApiRequest($this->keyPair, $method, route($route, $scheduled), [
        'body' => 'Too late for this.',
        'scheduled_at' => '2026-10-16T12:00:00+00:00',
    ])->assertConflict()
        ->assertExactJson([
            'message' => 'This commit was already submitted or canceled. Refresh to see its status.',
            'code' => 'scheduled_commit_submitted',
        ]);

    expect($scheduled->refresh()->scheduled_at->toIso8601String())->toBe('2026-10-15T12:00:00+00:00');
})->with([
    ['PUT', 'api-scheduled-commit.update'],
    ['DELETE', 'api-scheduled-commit.destroy'],
])->with(['submitted', 'canceled']);

it("hides someone else's scheduled commits", function (string $method, string $route): void {
    $scheduled = ScheduledCommit::factory()->create(['body' => 'Not yours to change.']);

    signedApiRequest($this->keyPair, $method, route($route, $scheduled), [
        'body' => 'Changed by someone else.',
        'scheduled_at' => '2026-10-16T12:00:00+00:00',
    ])->assertNotFound();

    expect($scheduled->refresh())
        ->body->toBe('Not yours to change.')
        ->canceled_at->toBeNull();
})->with([
    ['PUT', 'api-scheduled-commit.update'],
    ['DELETE', 'api-scheduled-commit.destroy'],
]);

it('requires a signed request to list, edit or cancel them', function (string $method, string $route): void {
    $scheduled = ScheduledCommit::factory()->for($this->user)->create();

    $this->json($method, route($route, $scheduled))->assertUnauthorized();

    expect($scheduled->refresh()->canceled_at)->toBeNull();
})->with([
    ['GET', 'api-scheduled-commit.index'],
    ['PUT', 'api-scheduled-commit.update'],
    ['DELETE', 'api-scheduled-commit.destroy'],
]);
