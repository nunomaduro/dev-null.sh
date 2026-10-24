<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateUserProfile;
use App\Models\User;
use App\Services\CommitTranslations;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Locale;

final readonly class UserProfileController
{
    public function edit(#[CurrentUser] User $user, CommitTranslations $translations): Response
    {
        return Inertia::render('user-profile/edit', [
            'translationLanguage' => $user->translation_language === null ? null : $translations->code($user->translation_language),
            'translationLanguages' => collect($translations->languages())->map(fn (string $code): array => [
                'code' => $code,
                'name' => Locale::getDisplayName($code, 'en'),
                'nativeName' => Locale::getDisplayName($code, $code),
            ])->sortBy('name')->values()->all(),
            'preferredLane' => $user->preferredLane(),
            'automaticLane' => $user->automaticLane(),
            'githubSyncedAt' => $user->github_synced_at?->toIso8601String(),
            'activityGraphUrl' => route('user-activity-graph.show', $user),
            'profileUrl' => route('user.show', $user),
        ]);
    }

    public function update(#[CurrentUser] User $user, UpdateUserProfile $action): RedirectResponse
    {
        $synced = $action->handle($user);

        Inertia::flash('toast', $synced ? [
            'type' => 'success',
            'message' => 'Synced from GitHub.',
        ] : [
            'type' => 'error',
            'message' => 'Could not sync from GitHub. Try again in a minute.',
        ]);

        return back();
    }
}
