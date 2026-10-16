<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\DirectMessage;
use App\Models\User;
use App\Queries\DirectMessagingQuery;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateDirectMessageRequest extends FormRequest
{
    public function authorize(): Response
    {
        $sender = $this->user();
        $recipient = $this->route('user');
        assert($sender instanceof User);
        assert($recipient instanceof User);
        $query = resolve(DirectMessagingQuery::class);
        $conversation = $query->between($sender, $recipient);
        $clientId = $this->input('client_id');
        $retry = is_string($clientId) && Str::isUuid($clientId)
            ? DirectMessage::query()->where('sender_id', $sender->id)->where('client_id', $clientId)->first() : null;

        if ($retry instanceof DirectMessage) {
            return $conversation?->id === $retry->conversation_id && is_string($this->input('body')) && $retry->body === $this->string('body')->trim()->value()
                && ! $sender->hasBlockWith($recipient)
                ? Response::allow() : throw ValidationException::withMessages(['client_id' => 'This send identifier has already been used.']);
        }

        return $query->canSend($sender, $recipient, $conversation)
            ? Response::allow() : throw ValidationException::withMessages(['body' => 'Messaging is unavailable. Check your settings or wait for your request to be accepted.']);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:4000'], 'client_id' => ['required', 'uuid']];
    }

    public function body(): string
    {
        return $this->string('body')->trim()->value();
    }

    public function clientId(): string
    {
        return $this->string('client_id')->value();
    }
}
