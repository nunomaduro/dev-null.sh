<?php

declare(strict_types=1);

use App\Actions\CreateDirectMessage;
use App\Actions\DeleteUser;
use App\Enums\NotificationType;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\DirectMessage;
use App\Models\Notification;
use App\Models\User;
use App\Queries\DirectMessagingQuery;
use App\Queries\NotificationGroupsQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->sender = User::factory()->create(['dm_policy' => 'following']);
    $this->recipient = User::factory()->create(['dm_policy' => 'everyone']);
    $this->query = resolve(DirectMessagingQuery::class);
});

function dmPayload(string $body = 'Hello, developer.'): array
{
    return ['body' => $body, 'client_id' => Str::uuid()->toString()];
}

function dmConversation(User $sender, User $recipient, bool $accepted = true): Conversation
{
    if ($accepted) {
        $recipient->following()->attach($sender);
    }

    return resolve(CreateDirectMessage::class)->handle($sender, $recipient, 'First private message', Str::uuid()->toString());
}

it('defaults to opted out and keeps messaging preferences off public user payloads', function (): void {
    $user = User::factory()->create();
    expect($user->dm_policy)->toBe('off')->and($user->dm_read_receipts)->toBeFalse()
        ->and($user->toArray())->not->toHaveKeys(['dm_policy', 'dm_read_receipts']);
    expect($this->query->unreadCount($user))->toBe(0);
});

it('saves private messaging settings', function (string $policy): void {
    $this->actingAs($this->sender)->fromRoute('messaging-settings.edit')
        ->put(route('messaging-settings.update'), ['dm_policy' => $policy, 'dm_read_receipts' => true])
        ->assertRedirectToRoute('messaging-settings.edit')->assertInertiaFlash('toast.type', 'success');
    expect($this->sender->fresh()->dm_policy)->toBe($policy)->and($this->sender->fresh()->dm_read_receipts)->toBeTrue();
    $this->get(route('messaging-settings.edit'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('messages/settings')->where('policy', $policy)->where('readReceipts', true));
})->with(['off', 'following', 'everyone']);

it('validates preferences', function (): void {
    $this->actingAs($this->sender)->putJson(route('messaging-settings.update'), ['dm_policy' => 'followers', 'dm_read_receipts' => 'yes'])
        ->assertUnprocessable()->assertJsonValidationErrors(['dm_policy', 'dm_read_receipts']);
});

it('requires authentication on every messaging surface', function (): void {
    $conversation = dmConversation($this->sender, $this->recipient);
    foreach (['conversation.index', 'conversation.create', 'messaging-settings.edit'] as $route) {
        $this->get(route($route))->assertRedirectToRoute('home');
    }

    $this->get(route('conversation.show', $conversation))->assertRedirectToRoute('home');
    $this->post(route('direct-message.store', $this->recipient), dmPayload())->assertRedirectToRoute('home');
    foreach (['conversation-read.update', 'conversation-preferences.update', 'conversation-request.update'] as $route) {
        $this->put(route($route, $conversation))->assertRedirectToRoute('home');
    }

    $this->put(route('messaging-settings.update'))->assertRedirectToRoute('home');
});

it('creates one request, encrypts its body, and notifies without copying private text', function (): void {
    $this->actingAs($this->sender)->postJson(route('direct-message.store', $this->recipient), dmPayload('A private introduction'))->assertRedirect();
    $conversation = Conversation::query()->sole();
    $message = DirectMessage::query()->sole();
    expect($conversation->status)->toBe('requested')->and($conversation->members()->count())->toBe(2)
        ->and($message->body)->toBe('A private introduction')
        ->and(DB::table('direct_messages')->value('body'))->not->toContain('A private introduction');
    $notification = Notification::query()->sole();
    expect($notification->type)->toBe(NotificationType::MessageRequested)
        ->and($notification->conversation_id)->toBe($conversation->id)
        ->and($notification->toJson())->not->toContain('A private introduction');
    expect($this->query->unreadCount($this->recipient))->toBe(1)
        ->and(resolve(NotificationGroupsQuery::class)->unreadCount($this->recipient))->toBe(1);
    $this->actingAs($this->recipient)->get(route('notification.index', ['type' => 'messages']))
        ->assertInertia(fn (Assert $page): Assert => $page->has('notifications.data', 1)->where('notifications.data.0.type', 'message_requested'));
    $this->getJson(route('notification-group.show', [$notification, 'type' => 'messages']))->assertOk()->assertJsonCount(1, 'data');
    expect($this->query->unreadCount($this->recipient))->toBe(1);
});

it('sends straight to the inbox only when the recipient follows the sender', function (): void {
    $this->recipient->update(['dm_policy' => 'following']);
    $this->recipient->following()->attach($this->sender);
    $this->actingAs($this->sender)->postJson(route('direct-message.store', $this->recipient), dmPayload())->assertRedirect();
    expect(Conversation::query()->sole()->status)->toBe('accepted')->and(Notification::query()->sole()->type)->toBe(NotificationType::Messaged);
});

it('does not reverse the direction of following permission', function (): void {
    $this->recipient->update(['dm_policy' => 'following']);
    $this->sender->following()->attach($this->recipient);
    $this->actingAs($this->sender)->postJson(route('direct-message.store', $this->recipient), dmPayload())->assertUnprocessable();
    expect(Conversation::query()->count())->toBe(0);
});

it('rejects self messages and disabled inboxes', function (string $scenario): void {
    $recipient = $this->recipient;
    if ($scenario === 'self') {
        $recipient = $this->sender;
    } elseif ($scenario === 'sender-off') {
        $this->sender->update(['dm_policy' => 'off']);
    } else {
        $recipient->update(['dm_policy' => 'off']);
    }

    $this->actingAs($this->sender)->postJson(route('direct-message.store', $recipient), dmPayload())->assertUnprocessable();
    expect(DirectMessage::query()->count())->toBe(0);
})->with(['self', 'sender-off', 'recipient-off']);

it('allows only one introductory message until acceptance and only the recipient can decide', function (): void {
    $conversation = dmConversation($this->sender, $this->recipient, false);
    $this->actingAs($this->sender)->postJson(route('direct-message.store', $this->recipient), dmPayload())->assertUnprocessable();
    $this->putJson(route('conversation-request.update', $conversation), ['status' => 'accepted'])->assertNotFound();
    $this->actingAs($this->recipient)->postJson(route('direct-message.store', $this->sender), dmPayload())->assertUnprocessable();
    $this->putJson(route('conversation-request.update', $conversation), ['status' => 'accepted'])->assertRedirect();
    $this->postJson(route('direct-message.store', $this->sender), dmPayload('Accepted and replied'))->assertRedirect();
    expect(Conversation::query()->count())->toBe(1)->and(DirectMessage::query()->count())->toBe(2);
    $this->putJson(route('conversation-request.update', $conversation), ['status' => 'declined'])->assertNotFound();
});

it('declines silently, prevents repeated requests, and lets the recipient reopen from archive', function (): void {
    $conversation = dmConversation($this->sender, $this->recipient, false);
    $this->actingAs($this->recipient)->putJson(route('conversation-request.update', $conversation), ['status' => 'declined'])->assertRedirect();
    expect(Notification::query()->count())->toBe(0);
    expect($this->query->unreadCount($this->recipient))->toBe(0);
    $this->get(route('conversation.index', ['folder' => 'archived']))->assertInertia(fn (Assert $page): Assert => $page->has('conversations.data', 1));
    $this->actingAs($this->sender)->get(route('conversation.show', $conversation))->assertInertia(fn (Assert $page): Assert => $page->where('conversation.status', 'requested')->where('conversation.can_send', false));
    $this->postJson(route('direct-message.store', $this->recipient), dmPayload())->assertUnprocessable();
    $this->actingAs($this->recipient)->putJson(route('conversation-request.update', $conversation), ['status' => 'accepted'])->assertRedirect();
    expect($conversation->fresh()->status)->toBe('accepted');
});

it('retains accepted conversations after unfollowing but pauses them when messaging is off', function (): void {
    $conversation = dmConversation($this->sender, $this->recipient);
    $this->recipient->following()->detach($this->sender);
    $this->recipient->update(['dm_policy' => 'following']);
    $this->actingAs($this->sender)->postJson(route('direct-message.store', $this->recipient), dmPayload())->assertRedirect();
    $this->recipient->update(['dm_policy' => 'off']);
    $this->postJson(route('direct-message.store', $this->recipient), dmPayload())->assertUnprocessable();
    $this->actingAs($this->recipient)->get(route('conversation.show', $conversation))->assertOk();
    expect(resolve(NotificationGroupsQuery::class)->unreadCount($this->recipient))->toBe(0);
});

it('allows declining with messages off but not accepting', function (): void {
    $conversation = dmConversation($this->sender, $this->recipient, false);
    $this->recipient->update(['dm_policy' => 'off']);
    $this->actingAs($this->recipient)->putJson(route('conversation-request.update', $conversation), ['status' => 'accepted'])->assertNotFound();
    $this->putJson(route('conversation-request.update', $conversation), ['status' => 'declined'])->assertRedirect();
});

it('makes retries idempotent without new notifications or reordering', function (): void {
    $payload = dmPayload();
    $this->actingAs($this->sender)->postJson(route('direct-message.store', $this->recipient), $payload)->assertRedirect();
    $createdAt = Notification::query()->sole()->created_at->toJSON();
    $this->travel(1)->minute();
    $this->postJson(route('direct-message.store', $this->recipient), $payload)->assertRedirect();
    expect(Conversation::query()->count())->toBe(1)->and(DirectMessage::query()->count())->toBe(1)
        ->and(Notification::query()->count())->toBe(1)->and(Notification::query()->sole()->created_at->toJSON())->toBe($createdAt);
    $this->postJson(route('direct-message.store', $this->recipient), [...$payload, 'body' => 'different'])->assertUnprocessable();
    $this->postJson(route('direct-message.store', $this->recipient), [...$payload, 'body' => ['invalid']])->assertUnprocessable();
    $other = User::factory()->create(['dm_policy' => 'everyone']);
    $this->postJson(route('direct-message.store', $other), $payload)->assertUnprocessable();
});

it('validates message length and client identifiers without database errors', function (array $payload): void {
    $this->actingAs($this->sender)->postJson(route('direct-message.store', $this->recipient), $payload)->assertUnprocessable();
    expect(DirectMessage::query()->count())->toBe(0);
})->with([
    fn (): array => ['body' => '   ', 'client_id' => Str::uuid()->toString()],
    fn (): array => ['body' => str_repeat('a', 4001), 'client_id' => Str::uuid()->toString()],
    fn (): array => ['body' => ['not text'], 'client_id' => Str::uuid()->toString()],
    [['body' => 'hello', 'client_id' => 'invalid']],
    [['body' => 'hello', 'client_id' => ['invalid']]],
    [['body' => 'hello']],
]);

it('does not expose conversations or actions to nonparticipants', function (): void {
    $conversation = dmConversation($this->sender, $this->recipient);
    $stranger = User::factory()->create(['dm_policy' => 'everyone']);
    $this->actingAs($stranger)->get(route('conversation.show', $conversation))->assertNotFound();
    foreach (['conversation-read.update', 'conversation-preferences.update', 'conversation-request.update'] as $route) {
        $this->putJson(route($route, $conversation), ['through' => DirectMessage::query()->sole()->id, 'muted' => true, 'archived' => true, 'status' => 'accepted'])->assertNotFound();
    }

    $this->get(route('conversation.index'))->assertInertia(fn (Assert $page): Assert => $page->has('conversations.data', 0));
    $this->get('/messages/not-a-uuid')->assertNotFound();
});

it('blocks messaging and access in both directions and hides notifications', function (bool $recipientBlocks): void {
    $conversation = dmConversation($this->sender, $this->recipient);
    $blocker = $recipientBlocks ? $this->recipient : $this->sender;
    $blocked = $recipientBlocks ? $this->sender : $this->recipient;
    $this->actingAs($blocker)->post(route('user-block.store', $blocked), ['return_to_messages' => true])->assertRedirectToRoute('conversation.index');
    foreach ([$this->sender, $this->recipient] as $viewer) {
        $other = $viewer->is($this->sender) ? $this->recipient : $this->sender;
        $this->actingAs($viewer)->get(route('conversation.show', $conversation))->assertNotFound();
        $this->postJson(route('direct-message.store', $other), dmPayload())->assertUnprocessable();
        $this->get(route('conversation.index'))->assertInertia(fn (Assert $page): Assert => $page->has('conversations.data', 0));
        expect($this->query->unreadCount($viewer))->toBe(0);
    }

    expect(resolve(NotificationGroupsQuery::class)->unreadCount($this->recipient))->toBe(0);
})->with([true, false]);

it('uses a monotonic read watermark and leaves later arrivals unread', function (): void {
    $conversation = dmConversation($this->sender, $this->recipient);
    $first = DirectMessage::query()->sole()->id;
    resolve(CreateDirectMessage::class)->handle($this->sender, $this->recipient, 'Later arrival', Str::uuid()->toString());
    $last = DirectMessage::query()->max('id');
    $this->actingAs($this->recipient)->putJson(route('conversation-read.update', $conversation), ['through' => $first])->assertRedirect();
    expect($this->query->unreadCount($this->recipient))->toBe(1)->and(Notification::query()->sole()->read_at)->toBeNull();
    $this->putJson(route('conversation-read.update', $conversation), ['through' => $last])->assertRedirect();
    $this->putJson(route('conversation-read.update', $conversation), ['through' => $first])->assertRedirect();
    expect($this->query->unreadCount($this->recipient))->toBe(0)->and(Notification::query()->sole()->read_at)->not->toBeNull()
        ->and($conversation->members()->where('user_id', $this->recipient->id)->sole()->last_read_message_id)->toBe($last);
});

it('rejects read watermarks from other conversations', function (): void {
    $conversation = dmConversation($this->sender, $this->recipient);
    $other = User::factory()->create(['dm_policy' => 'everyone']);
    $foreign = dmConversation($this->sender, $other)->messages()->sole();
    $this->actingAs($this->recipient)->putJson(route('conversation-read.update', $conversation), ['through' => $foreign->id])->assertUnprocessable();
});

it('shares receipts only after acceptance and only when both people opt in', function (bool $senderReceipt, bool $recipientReceipt, bool $accepted): void {
    $this->sender->update(['dm_read_receipts' => $senderReceipt]);
    $this->recipient->update(['dm_read_receipts' => $recipientReceipt]);
    $conversation = dmConversation($this->sender, $this->recipient, $accepted);
    $id = DirectMessage::query()->sole()->id;
    $this->actingAs($this->recipient)->putJson(route('conversation-read.update', $conversation), ['through' => $id])->assertRedirect();
    $this->actingAs($this->sender)->get(route('conversation.show', $conversation))->assertInertia(fn (Assert $page): Assert => $page
        ->where('conversation.read_through', $accepted && $senderReceipt && $recipientReceipt ? $id : null));
})->with([[false, false, true], [true, false, true], [false, true, true], [true, true, true], [true, true, false]]);

it('does not mark messages read merely by fetching or prefetching the conversation', function (): void {
    $conversation = dmConversation($this->sender, $this->recipient);
    $response = $this->actingAs($this->recipient)->get(route('conversation.show', $conversation));
    $response->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($this->query->unreadCount($this->recipient))->toBe(1);
});

it('mutes notification delivery without blocking messages and archives per participant', function (): void {
    $conversation = dmConversation($this->sender, $this->recipient);
    $this->actingAs($this->recipient)->putJson(route('conversation-preferences.update', $conversation), ['muted' => true, 'archived' => true])->assertRedirect();
    expect($conversation->members()->where('user_id', $this->sender->id)->sole()->archived)->toBeFalse();
    expect($this->query->unreadCount($this->recipient))->toBe(0)
        ->and(resolve(NotificationGroupsQuery::class)->unreadCount($this->recipient))->toBe(0);
    Notification::query()->delete();
    $this->actingAs($this->sender)->postJson(route('direct-message.store', $this->recipient), dmPayload())->assertRedirect();
    expect(Notification::query()->count())->toBe(0)->and($conversation->members()->where('user_id', $this->recipient->id)->sole()->archived)->toBeFalse();
    $this->actingAs($this->recipient)->putJson(route('conversation-preferences.update', $conversation), ['muted' => false, 'archived' => false])->assertRedirect();
    expect($this->query->unreadCount($this->recipient))->toBe(1);
});

it('respects the existing account mute for delivery and unread badges', function (): void {
    $this->recipient->muting()->attach($this->sender);
    dmConversation($this->sender, $this->recipient);
    expect(Notification::query()->count())->toBe(0)->and($this->query->unreadCount($this->recipient))->toBe(0);
    $this->actingAs($this->recipient)->get(route('conversation.index'))->assertInertia(fn (Assert $page): Assert => $page->has('conversations.data', 1));
});

it('separates requests, sent requests, inbox, archives and searches', function (): void {
    $conversation = dmConversation($this->sender, $this->recipient, false);
    $this->actingAs($this->recipient)->get(route('conversation.index'))->assertInertia(fn (Assert $page): Assert => $page->has('conversations.data', 0));
    $this->get(route('conversation.index', ['folder' => 'requests', 'search' => $this->sender->username]))->assertInertia(fn (Assert $page): Assert => $page->has('conversations.data', 1)->where('conversations.data.0.incoming_request', true));
    $this->get(route('conversation.index', ['folder' => 'requests', 'search' => 'no-such-developer']))->assertInertia(fn (Assert $page): Assert => $page->has('conversations.data', 0));
    $this->actingAs($this->sender)->get(route('conversation.index'))->assertInertia(fn (Assert $page): Assert => $page->where('conversations.data.0.id', $conversation->id)->where('conversations.data.0.incoming_request', false));
    $this->getJson(route('conversation.index', ['folder' => 'bad']))->assertUnprocessable();
});

it('provides a private new-message page with guarded availability', function (): void {
    $this->actingAs($this->sender)->get(route('conversation.create'))->assertInertia(fn (Assert $page): Assert => $page->where('recipient', null)->where('canSend', false));
    $this->get(route('conversation.create', ['username' => $this->recipient->username]))->assertInertia(fn (Assert $page): Assert => $page->where('recipient.id', $this->recipient->id)->where('canSend', true));
    $conversation = dmConversation($this->sender, $this->recipient, false);
    $this->get(route('conversation.create', ['username' => $this->recipient->username]))->assertInertia(fn (Assert $page): Assert => $page->where('existing', $conversation->id)->where('canSend', false));
    $this->getJson(route('conversation.create', ['username' => 'missing-user']))->assertUnprocessable();
});

it('paginates message history without duplicates', function (): void {
    $conversation = dmConversation($this->sender, $this->recipient);
    for ($i = 0; $i < 51; $i++) {
        DirectMessage::query()->create(['conversation_id' => $conversation->id, 'sender_id' => $this->sender->id, 'client_id' => Str::uuid()->toString(), 'body' => 'History '.$i, 'created_at' => now()]);
    }

    $response = $this->actingAs($this->recipient)->get(route('conversation.show', $conversation))->assertInertia(fn (Assert $page): Assert => $page->has('messages.data', 50)->where('isOlderPage', false));
    $next = $response->viewData('page')['props']['messages']['next_page_url'];
    $this->get($next)->assertInertia(fn (Assert $page): Assert => $page->has('messages.data', 2)->where('isOlderPage', true));
});

it('coalesces messages into one notification per conversation and recipient', function (): void {
    dmConversation($this->sender, $this->recipient);
    $this->actingAs($this->sender)->postJson(route('direct-message.store', $this->recipient), dmPayload())->assertRedirect();
    expect(Notification::query()->count())->toBe(1)->and(resolve(NotificationGroupsQuery::class)->unreadCount($this->recipient))->toBe(1);
});

it('rate limits messages and new requests independently', function (): void {
    $conversation = dmConversation($this->sender, $this->recipient);
    // Exercise the route's actual named limiter until it rejects the request.
    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($this->sender)->postJson(route('direct-message.store', $this->recipient), dmPayload())->assertRedirect();
    }

    $this->postJson(route('direct-message.store', $this->recipient), dmPayload())->assertTooManyRequests();
    expect($conversation->messages()->count())->toBe(31);
});

it('limits unsolicited requests per day', function (): void {
    for ($i = 0; $i < 10; $i++) {
        $recipient = User::factory()->create(['dm_policy' => 'everyone']);
        $this->actingAs($this->sender)->postJson(route('direct-message.store', $recipient), dmPayload())->assertRedirect();
    }

    $this->postJson(route('direct-message.store', $this->recipient), dmPayload())->assertTooManyRequests();
});

it('exports only authored message bodies and messaging preferences', function (): void {
    $conversation = dmConversation($this->sender, $this->recipient);
    resolve(CreateDirectMessage::class)->handle($this->recipient, $this->sender, 'Their private reply', Str::uuid()->toString());
    $response = $this->actingAs($this->sender)->get(route('export'))->assertOk();
    $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
    expect($data['schema_version'])->toBe(3)->and($data['direct_messages'])->toHaveCount(1)
        ->and($data['direct_messages'][0]['body'])->toBe('First private message')
        ->and($data['direct_messages'][0]['conversation_id'])->toBe($conversation->id)
        ->and($data['profile']['dm_policy'])->toBe('following')
        ->and($response->streamedContent())->not->toContain('Their private reply');
});

it('removes the private conversation and notifications when either account is deleted', function (): void {
    dmConversation($this->sender, $this->recipient);
    resolve(DeleteUser::class)->handle($this->sender);
    expect(Conversation::query()->count())->toBe(0)->and(ConversationMember::query()->count())->toBe(0)
        ->and(DirectMessage::query()->count())->toBe(0)->and(Notification::query()->count())->toBe(0);
});

it('keeps incoming messages unread when the latest message was sent by the viewer', function (): void {
    $conversation = dmConversation($this->sender, $this->recipient);
    $this->actingAs($this->recipient)->postJson(route('direct-message.store', $this->sender), dmPayload('A reply without a read acknowledgement'))->assertRedirect();
    expect($this->query->unreadCount($this->recipient))->toBe(1);
    $this->get(route('conversation.index'))->assertInertia(fn (Assert $page): Assert => $page->where('conversations.data.0.unread', true));
    $this->get(route('conversation.show', $conversation))->assertInertia(fn (Assert $page): Assert => $page->where('conversation.unread', true));
});
