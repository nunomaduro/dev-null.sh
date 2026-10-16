<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\User;
use App\Services\CommitImpressionToken;
use Illuminate\Support\Facades\Crypt;

it('never issues a token for guests own unapproved deleted or unsaved commits', function (): void {
    $viewer = User::factory()->create();
    $approved = Commit::factory()->create();
    $own = Commit::factory()->for($viewer)->create();
    $pending = Commit::factory()->pending()->create();
    $rejected = Commit::factory()->rejected()->create();
    $deleted = Commit::factory()->create();
    $deleted->delete();

    $tokens = resolve(CommitImpressionToken::class);

    expect($tokens->issue($approved, null))->toBeNull()
        ->and($tokens->issue($own, $viewer))->toBeNull()
        ->and($tokens->issue($pending, $viewer))->toBeNull()
        ->and($tokens->issue($rejected, $viewer))->toBeNull()
        ->and($tokens->issue($deleted, $viewer))->toBeNull()
        ->and($tokens->issue(Commit::factory()->make(), $viewer))->toBeNull();

    $this->actingAs($viewer);
    expect($pending->toArray()['impression_token'])->toBeNull()
        ->and($pending->toArray()['impression_token_expires_at'])->toBeNull()
        ->and($deleted->toArray())->not->toHaveKeys(['impression_token', 'impression_token_expires_at']);
});

it('authenticates tokens and rejects malformed or unrelated encrypted payloads', function (): void {
    $viewer = User::factory()->create();
    $commit = Commit::factory()->create();
    $tokens = resolve(CommitImpressionToken::class);
    $issued = $tokens->issue($commit, $viewer);
    $payload = json_decode(Crypt::decryptString($issued['token']), true, flags: JSON_THROW_ON_ERROR);

    expect($tokens->validFor($viewer, $commit->id, $issued['token']))->toBeTrue()
        ->and($issued['expires_at'])->toBe(now()->addMinutes(10)->getTimestamp())
        ->and(mb_strlen($issued['token']))->toBeLessThanOrEqual(CommitImpressionToken::MAX_LENGTH)
        ->and($tokens->validFor($viewer, $commit->id, ''))->toBeFalse()
        ->and($tokens->validFor($viewer, $commit->id, str_repeat('a', CommitImpressionToken::MAX_LENGTH + 1)))->toBeFalse()
        ->and($tokens->validFor($viewer, $commit->id, substr_replace($issued['token'], '!', 20, 1)))->toBeFalse()
        ->and($tokens->validFor($viewer, $commit->id, Crypt::encryptString('not json')))->toBeFalse()
        ->and($tokens->validFor($viewer, $commit->id, Crypt::encryptString('null')))->toBeFalse()
        ->and($tokens->validFor($viewer, $commit->id, Crypt::encryptString(json_encode([...$payload, 'purpose' => 'other:v1'], JSON_THROW_ON_ERROR))))->toBeFalse()
        ->and($tokens->validFor($viewer, $commit->id, Crypt::encryptString(json_encode([...$payload, 'expires_at' => (string) $payload['expires_at']], JSON_THROW_ON_ERROR))))->toBeFalse();
});
