<?php

declare(strict_types=1);

use App\Enums\TuiTab;

it('has the expected enum values', function (): void {
    expect(array_column(TuiTab::cases(), 'value', 'name'))->toBe([
        'Everyone' => 'everyone',
        'Following' => 'following',
        'Thread' => 'thread',
        'Compose' => 'compose',
        'Profile' => 'profile',
        'Help' => 'help',
    ]);
});

it('provides readable tab labels with keyboard shortcuts', function (): void {
    expect(TuiTab::Everyone->label())->toBe('[1] Everyone')
        ->and(TuiTab::Following->label())->toBe('[2] Following')
        ->and(TuiTab::Thread->label())->toBe('[3] Thread')
        ->and(TuiTab::Compose->label())->toBe('[4] New Post')
        ->and(TuiTab::Profile->label())->toBe('[5] Profile & Ranks')
        ->and(TuiTab::Help->label())->toBe('[?] Help');
});
