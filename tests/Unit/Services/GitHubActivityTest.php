<?php

declare(strict_types=1);

use App\Services\GitHubActivity;

it('recognizes an activity commit', function (string $body): void {
    expect(resolve(GitHubActivity::class)->matches($body))->toBeTrue();
})->with([
    'plain' => '/activity',
    'caps' => '/Activity',
    'padded' => "  /activity\n",
]);

it('ignores anything that is not only the command', function (string $body): void {
    expect(resolve(GitHubActivity::class)->matches($body))->toBeFalse();
})->with([
    'no slash' => 'activity',
    'extra words' => '/activity of the week',
    'quoted' => '`/activity`',
    'empty' => '',
]);

it('reads the contribution calendar, day by day, in order', function (): void {
    $days = resolve(GitHubActivity::class)->parse((string) file_get_contents(base_path('tests/Fixtures/github/contributions.html')));

    expect($days)->toBe([
        ['date' => '2025-10-12', 'count' => 0, 'level' => 0],
        ['date' => '2025-10-13', 'count' => 1, 'level' => 1],
        ['date' => '2025-10-19', 'count' => 1234, 'level' => 4],
        ['date' => '2025-10-20', 'count' => 0, 'level' => 1],
    ]);
});

it('has no calendar when the page no longer draws one the way it used to', function (string $html): void {
    expect(resolve(GitHubActivity::class)->parse($html))->toBeNull();
})->with([
    'no calendar' => '<p>Not Found</p>',
    'an odd date' => '<table><tr><td class="ContributionCalendar-day" data-date="yesterday" data-level="1"></td></tr></table>',
    'an odd level' => '<table><tr><td class="ContributionCalendar-day" data-date="2025-10-12" data-level="9"></td></tr></table>',
]);
