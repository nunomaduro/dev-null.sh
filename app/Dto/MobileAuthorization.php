<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * What the devnull app asks for when it sends the user to approve it on the web: which key and device to approve,
 * the app's PKCE challenge, and where to send the answer, with the state the app gave.
 */
final readonly class MobileAuthorization
{
    public function __construct(
        public string $fingerprint,
        public string $deviceName,
        public string $codeChallenge,
        public string $state,
        public string $redirectUri,
    ) {
        //
    }

    /**
     * The app's redirect_uri with the given parameters and the state added to its query.
     *
     * @param  array<string, string>  $parameters
     */
    public function redirectTo(array $parameters): string
    {
        return $this->redirectUri.(str_contains($this->redirectUri, '?') ? '&' : '?').http_build_query([...$parameters, 'state' => $this->state]);
    }

    /**
     * The parameters as the app sent them, for the approval form to send back.
     *
     * @return array{fingerprint: string, device_name: string, code_challenge: string, code_challenge_method: string, state: string, redirect_uri: string}
     */
    public function toParameters(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'device_name' => $this->deviceName,
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => $this->state,
            'redirect_uri' => $this->redirectUri,
        ];
    }
}
