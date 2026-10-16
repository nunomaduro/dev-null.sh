<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\User;
use Illuminate\Support\Facades\Http;

it('shows the github activity of the author of an activity commit, to guests too', function (): void {
    $author = User::factory()->create();
    $commit = Commit::factory()->for($author)->create(['body' => '/activity']);
    Http::fake([
        "api.github.com/user/{$author->github_id}" => Http::response(['login' => 'nunomaduro']),
        'github.com/users/nunomaduro/contributions' => Http::response((string) file_get_contents(base_path('tests/Fixtures/github/contributions.html'))),
    ]);

    $this->getJson(route('commit-github-activity.show', $commit))
        ->assertOk()
        ->assertJsonPath('activity.login', 'nunomaduro')
        ->assertJsonCount(4, 'activity.days')
        ->assertJsonPath('activity.days.2', ['date' => '2025-10-19', 'count' => 1234, 'level' => 4]);
});

it('has no activity once the author is gone', function (): void {
    $commit = Commit::factory()->create(['body' => '/activity', 'user_id' => null]);

    $this->getJson(route('commit-github-activity.show', $commit))
        ->assertOk()
        ->assertExactJson(['activity' => null]);

    Http::assertNothingSent();
});

it('has no activity for other commits', function (): void {
    $commit = Commit::factory()->create(['body' => 'not the command']);

    $this->getJson(route('commit-github-activity.show', $commit))->assertNotFound();

    Http::assertNothingSent();
});

it('hides the activity of commits the viewer cannot see', function (): void {
    $author = User::factory()->create();
    $commit = Commit::factory()->for($author)->pending()->create(['body' => '/activity']);
    Http::fake(["api.github.com/user/{$author->github_id}" => Http::response([], 404)]);

    $this->getJson(route('commit-github-activity.show', $commit))->assertNotFound();
    $this->actingAs(User::factory()->create())->getJson(route('commit-github-activity.show', $commit))->assertNotFound();
    $this->actingAs($author)->getJson(route('commit-github-activity.show', $commit))->assertOk();
});
