<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateUserOnboardedAt;
use App\Http\Requests\ShowOnboardingRequest;
use App\Models\User;
use App\Queries\SuggestedUsersQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final readonly class OnboardingController
{
    public function show(ShowOnboardingRequest $request, #[CurrentUser] User $user, SuggestedUsersQuery $suggested): Response
    {
        return Inertia::render('onboarding/show', [
            'preferredLane' => $user->preferredLane(),
            'automaticLane' => $user->automaticLane(),
            'suggestions' => fn (): Collection => $suggested->get($user, limit: 5),
            'commitScriptUrl' => route('cli-script.show'),
        ]);
    }

    /**
     * Finishing and skipping both end the onboarding for good.
     */
    public function update(#[CurrentUser] User $user, UpdateUserOnboardedAt $action): RedirectResponse
    {
        $action->handle($user);

        return redirect()->intended(route('log.show', $user->log, absolute: false));
    }
}
