<?php

declare(strict_types=1);

use App\Enums\NotificationType;

it('has the types of a notification', function (): void {
    expect(array_column(NotificationType::cases(), 'value', 'name'))->toBe([
        'Messaged' => 'messaged',
        'MessageRequested' => 'message_requested',
        'Followed' => 'followed',
        'Replied' => 'replied',
        'Mentioned' => 'mentioned',
        'Approved' => 'approved',
        'Rejected' => 'rejected',
        'Quoted' => 'quoted',
        'Starred' => 'starred',
    ]);
});
