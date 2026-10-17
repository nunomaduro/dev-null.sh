<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateAuthorizationCode;
use App\Http\Requests\CreateMobileAuthorizationRequest;
use App\Http\Requests\ShowMobileAuthorizationRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final readonly class MobileAuthorizationController
{
    /**
     * Asks the user to approve the devnull app on a device. The page approves with a plain form post rather than an
     * Inertia visit, so the browser follows the redirect back into the app, and cancelling is a plain link there.
     */
    public function show(ShowMobileAuthorizationRequest $request): Response
    {
        $authorization = $request->authorization();

        return Inertia::render('mobile-authorization/show', [
            'authorization' => $authorization->toParameters(),
            'cancelUrl' => $authorization->redirectTo(['error' => 'access_denied']),
            'csrfToken' => csrf_token(),
        ]);
    }

    /**
     * Sends the user back to the app with a one-time code, which the app redeems for a device with its PKCE verifier.
     */
    public function store(CreateMobileAuthorizationRequest $request, #[CurrentUser] User $user, CreateAuthorizationCode $action): RedirectResponse
    {
        $authorization = $request->authorization();

        $code = $action->handle($user, $authorization);

        return redirect()->away($authorization->redirectTo(['code' => $code]), 303);
    }
}
