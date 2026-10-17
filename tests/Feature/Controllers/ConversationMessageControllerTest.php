<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Str;

it('starts one conversation between two people and keeps their messages in it', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    $clientId = Str::uuid()->toString();

    $this->actingAs($mark)->postJson(route('conversation-message.store', $nuno), [
        'body' => '  merged it this morning  ',
        'client_id' => $clientId,
    ])->assertCreated()
        ->assertJsonPath('message.body', 'merged it this morning')
        ->assertJsonPath('message.client_id', $clientId)
        ->assertJsonPath('message.user_id', $mark->id);

    sendChatMessage($nuno, $mark, 'ship it');

    expect(Conversation::query()->count())->toBe(1)
        ->and(Conversation::query()->sole()->messages()->pluck('body')->all())->toBe(['merged it this morning', 'ship it']);
});

it('stores a retried message only once', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    $clientId = Str::uuid()->toString();

    $first = $this->actingAs($mark)->postJson(route('conversation-message.store', $nuno), ['body' => 'on it', 'client_id' => $clientId])->assertCreated();
    $retry = $this->postJson(route('conversation-message.store', $nuno), ['body' => 'on it', 'client_id' => $clientId])->assertCreated();

    expect($retry->json('message.id'))->toBe($first->json('message.id'))
        ->and(Message::query()->count())->toBe(1);
});

it('rejects invalid messages', function (array $payload, string $error): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();

    $this->actingAs($mark)->postJson(route('conversation-message.store', $nuno), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($error);

    expect(Message::query()->count())->toBe(0);
})->with([
    'missing body' => [fn (): array => ['client_id' => Str::uuid()->toString()], 'body'],
    'blank body' => [fn (): array => ['body' => '   ', 'client_id' => Str::uuid()->toString()], 'body'],
    'too long' => [fn (): array => ['body' => str_repeat('a', Message::MAX_LENGTH + 1), 'client_id' => Str::uuid()->toString()], 'body'],
    'missing client id' => [fn (): array => ['body' => 'hi'], 'client_id'],
    'invalid client id' => [fn (): array => ['body' => 'hi', 'client_id' => 'nope'], 'client_id'],
]);

it('hides people you cannot message', function (string $who): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();

    match ($who) {
        'blocked' => $mark->blocking()->attach($nuno),
        'blocker' => $nuno->blocking()->attach($mark),
        default => null,
    };

    $recipient = $who === 'yourself' ? $mark : $nuno;

    $this->actingAs($mark)->postJson(route('conversation-message.store', $recipient), ['body' => 'hi', 'client_id' => Str::uuid()->toString()])
        ->assertNotFound();
    $this->getJson(route('conversation-message.index', $recipient))->assertNotFound();

    expect(Message::query()->count())->toBe(0);
})->with(['yourself', 'blocked', 'blocker']);

it('returns the latest messages, then only newer ones, then older pages', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    $messages = collect(range(1, 55))->map(fn (int $index): Message => sendChatMessage($index % 2 === 0 ? $mark : $nuno, $index % 2 === 0 ? $nuno : $mark, "message {$index}"));

    $latest = $this->actingAs($mark)->getJson(route('conversation-message.index', $nuno))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('partner.username', $nuno->username)
        ->assertJsonPath('has_older', true);

    expect($latest->json('messages'))->toHaveCount(50)
        ->and($latest->json('messages.0.body'))->toBe('message 6')
        ->and($latest->json('messages.49.body'))->toBe('message 55');

    $older = $this->getJson(route('conversation-message.index', [$nuno, 'before' => $latest->json('messages.0.id')]))->assertOk();

    expect(collect($older->json('messages'))->pluck('body')->all())->toBe(['message 1', 'message 2', 'message 3', 'message 4', 'message 5'])
        ->and($older->json('has_older'))->toBeFalse();

    $newest = sendChatMessage($nuno, $mark, 'one more');

    $newer = $this->actingAs($mark)->getJson(route('conversation-message.index', [$nuno, 'after' => $messages->last()->id]))->assertOk();

    expect(collect($newer->json('messages'))->pluck('id')->all())->toBe([$newest->id]);
});

it('returns an empty thread before anyone has written', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();

    $this->actingAs($mark)->getJson(route('conversation-message.index', $nuno))
        ->assertOk()
        ->assertJsonPath('messages', [])
        ->assertJsonPath('partner_read_through_id', 0)
        ->assertJsonPath('has_older', false);

    expect(Conversation::query()->count())->toBe(0);
});

it('requires authentication', function (): void {
    $nuno = User::factory()->withRemotes()->create();

    $this->postJson(route('conversation-message.store', $nuno), ['body' => 'hi', 'client_id' => Str::uuid()->toString()])->assertUnauthorized();
    $this->getJson(route('conversation-message.index', $nuno))->assertUnauthorized();
});
