<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Dto\SshPublicKey;
use App\Models\AuthorizationCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthorizationCode>
 */
final class AuthorizationCodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'code_hash' => AuthorizationCode::hash(bin2hex(random_bytes(32))),
            'fingerprint' => 'SHA256:'.mb_rtrim(base64_encode(random_bytes(32)), '='),
            'device_name' => 'devnull on iPhone',
            'code_challenge' => AuthorizationCode::challengeFor(bin2hex(random_bytes(32))),
            'redirect_uri' => 'devnull://auth/callback',
            'expires_at' => now()->addMinutes(AuthorizationCode::EXPIRES_AFTER_MINUTES),
        ];
    }

    /**
     * The code the app was handed, whose hash is stored.
     */
    public function withCode(string $code): self
    {
        return $this->state(fn (array $attributes): array => [
            'code_hash' => AuthorizationCode::hash($code),
        ]);
    }

    /**
     * The PKCE challenge for the verifier the app keeps.
     */
    public function withVerifier(string $verifier): self
    {
        return $this->state(fn (array $attributes): array => [
            'code_challenge' => AuthorizationCode::challengeFor($verifier),
        ]);
    }

    public function withPublicKey(SshPublicKey $publicKey): self
    {
        return $this->state(fn (array $attributes): array => [
            'fingerprint' => $publicKey->fingerprint(),
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subSecond(),
        ]);
    }
}
