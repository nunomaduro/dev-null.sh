<?php

declare(strict_types=1);

use App\Models\User;
use App\Queries\ConversationsQuery;

it('lists conversations by latest message with what is unread', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create(['username' => 'nunomaduro']);
    $taylor = User::factory()->withRemotes()->create(['username' => 'taylorotwell']);
    sendChatMessage($mark, $taylor, 'thanks!');
    sendChatMessage($nuno, $mark, 'did you see the PR?');
    sendChatMessage($nuno, $mark, 'ship it');

    $response = $this->actingAs($mark)->getJson(route('inbox.index'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('unread', 2);

    expect($response->json('conversations'))->toHaveCount(2)
        ->and($response->json('conversations.0.partner.username'))->toBe('nunomaduro')
        ->and($response->json('conversations.0.ahead'))->toBe(2)
        ->and($response->json('conversations.0.last_message.body'))->toBe('ship it')
        ->and($response->json('conversations.1.partner.username'))->toBe('taylorotwell')
        ->and($response->json('conversations.1.ahead'))->toBe(0)
        ->and($response->json('conversations.1.last_message.user_id'))->toBe($mark->id);
});

it('clears what is unread once read', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    $message = sendChatMessage($nuno, $mark, 'ship it');

    $this->actingAs($mark)->putJson(route('conversation-read.update', $nuno), ['through' => $message->id]);

    $this->getJson(route('inbox.index'))->assertJsonPath('unread', 0)->assertJsonPath('conversations.0.ahead', 0);
});

it('leaves out conversations across a block', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    sendChatMessage($nuno, $mark, 'hey');
    $mark->blocking()->attach($nuno);

    $this->actingAs($mark)->getJson(route('inbox.index'))->assertJsonPath('conversations', [])->assertJsonPath('unread', 0);
});

it('requires authentication', function (): void {
    $this->getJson(route('inbox.index'))->assertUnauthorized();
});

it('searches conversations by name or handle', function (string $term): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create(['username' => 'nunomaduro', 'name' => 'Nuno Maduro']);
    $taylor = User::factory()->withRemotes()->create(['username' => 'taylorotwell', 'name' => 'Taylor Otwell']);
    sendChatMessage($nuno, $mark, 'ship it');
    sendChatMessage($taylor, $mark, 'thanks!');

    $response = $this->actingAs($mark)->getJson(route('inbox.index', ['q' => $term]))->assertOk();

    expect(collect($response->json('conversations'))->pluck('partner.username')->all())->toBe(['nunomaduro']);
})->with(['nuno', '@NunoMad', 'maduro']);

it('validates the inbox filters', function (array $query, string $error): void {
    $this->actingAs(User::factory()->withRemotes()->create())->getJson(route('inbox.index', $query))
        ->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    [fn (): array => ['tab' => 'maybe'], 'tab'],
    [fn (): array => ['q' => str_repeat('a', 101)], 'q'],
]);

it('pages through conversations, newest first', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $partners = User::factory()->withRemotes()->count(ConversationsQuery::PAGE + 2)->create();
    $partners->each(function (User $partner, int $index) use ($mark): void {
        $this->travel(1)->minutes();
        sendChatMessage($partner, $mark, "message {$index}");
    });

    $first = $this->actingAs($mark)->getJson(route('inbox.index'))->assertJsonPath('has_more', true);

    expect($first->json('conversations'))->toHaveCount(ConversationsQuery::PAGE)
        ->and($first->json('conversations.0.last_message.body'))->toBe('message '.(ConversationsQuery::PAGE + 1));

    $second = $this->getJson(route('inbox.index', ['before' => $first->json('conversations.'.(ConversationsQuery::PAGE - 1).'.id')]))
        ->assertJsonPath('has_more', false);

    expect(collect($second->json('conversations'))->pluck('last_message.body')->all())->toBe(['message 1', 'message 0']);
});
