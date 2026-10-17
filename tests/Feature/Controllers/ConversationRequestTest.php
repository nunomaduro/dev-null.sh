<?php

declare(strict_types=1);

use App\Enums\RemotesFrom;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function pushTo(User $sender, User $recipient, string $body = 'hello'): TestResponse
{
    return test()->actingAs($sender)->postJson(route('conversation-message.store', $recipient), [
        'body' => $body,
        'client_id' => Str::uuid()->toString(),
    ]);
}

it('fetches a first message from someone the recipient does not follow as a request', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes(RemotesFrom::Following)->create(['username' => 'nunomaduro']);

    pushTo($mark, $nuno, 'hey, saw your polling PR')->assertCreated();

    $this->actingAs($nuno)->getJson(route('inbox.index'))->assertJsonPath('conversations', [])->assertJsonPath('requests', 1)->assertJsonPath('unread', 1);
    $this->getJson(route('inbox.index', ['tab' => 'fetched']))->assertJsonPath('conversations.0.partner.id', $mark->id);
    $this->getJson(route('conversation-message.index', $mark))->assertJsonPath('access', 'request');

    $this->actingAs($mark)->getJson(route('conversation-message.index', $nuno))->assertJsonPath('access', 'waiting');
    pushTo($mark, $nuno, 'one more')
        ->assertForbidden()
        ->assertJsonPath('message', "@nunomaduro hasn't merged your request yet.");
    pushTo($nuno, $mark, 'hi')->assertForbidden();
});

it('opens the conversation straight away for people the recipient follows, or for everyone', function (RemotesFrom $from, bool $follows): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes($from)->create();

    if ($follows) {
        $nuno->following()->attach($mark);
    }

    pushTo($mark, $nuno)->assertCreated();

    $this->actingAs($nuno)->getJson(route('inbox.index'))->assertJsonCount(1, 'conversations')->assertJsonPath('requests', 0);
    $this->getJson(route('conversation-message.index', $mark))->assertJsonPath('access', 'open');
})->with([
    'following, and follows them' => [RemotesFrom::Following, true],
    'everyone' => [RemotesFrom::Everyone, false],
]);

it('keeps requests fetched after switching to everyone', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes(RemotesFrom::Following)->create();
    pushTo($mark, $nuno)->assertCreated();

    $this->actingAs($nuno)->put(route('user-remote-preferences.update'), ['from' => 'everyone', 'pull_receipts' => true]);

    $this->getJson(route('inbox.index', ['tab' => 'fetched']))->assertJsonCount(1, 'conversations');
    $this->actingAs($mark)->getJson(route('conversation-message.index', $nuno))->assertJsonPath('access', 'waiting');
});

it('merges a request into open conversations', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes(RemotesFrom::Following)->create();
    pushTo($mark, $nuno)->assertCreated();

    $this->actingAs($nuno)->postJson(route('conversation-merge.store', $mark))->assertNoContent();

    $this->getJson(route('inbox.index'))->assertJsonCount(1, 'conversations')->assertJsonPath('requests', 0);
    pushTo($nuno, $mark, 'welcome')->assertCreated();
    pushTo($mark, $nuno, 'thanks')->assertCreated();
    $this->postJson(route('conversation-merge.store', $mark))->assertNotFound();
});

it('drops a request without telling the sender', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes(RemotesFrom::Following)->create();
    pushTo($mark, $nuno)->assertCreated();

    $this->actingAs($nuno)->postJson(route('conversation-drop.store', $mark))->assertNoContent();

    $this->getJson(route('inbox.index', ['tab' => 'fetched']))->assertJsonPath('conversations', []);
    $this->getJson(route('inbox.index'))->assertJsonPath('conversations', [])->assertJsonPath('unread', 0);
    $this->actingAs($mark)->getJson(route('conversation-message.index', $nuno))->assertJsonPath('access', 'waiting');
    pushTo($mark, $nuno)->assertForbidden();

    pushTo($nuno, $mark, 'actually, hi')->assertCreated();
    $this->actingAs($mark)->getJson(route('conversation-message.index', $nuno))->assertJsonPath('access', 'open');
});

it('only merges or drops fetched requests', function (string $route): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    pushTo($mark, $nuno)->assertCreated();

    $this->actingAs($nuno)->postJson(route($route, $mark))->assertNotFound();
    $this->actingAs($mark)->postJson(route($route, $nuno))->assertNotFound();
})->with(['conversation-merge.store', 'conversation-drop.store']);
