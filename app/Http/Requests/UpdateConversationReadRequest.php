<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateConversationReadRequest extends FormRequest
{
    public function authorize(): Response
    {
        $user = $this->user();
        $conversation = $this->route('conversation');
        assert($user instanceof User);
        assert($conversation instanceof Conversation);
        $other = User::query()->findOrFail($conversation->otherId($user));

        return $conversation->includes($user) && ! $user->hasBlockWith($other)
            ? Response::allow() : Response::denyAsNotFound();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $conversation = $this->route('conversation');
        assert($conversation instanceof Conversation);

        return ['through' => ['required', 'integer', 'min:1', Rule::exists('direct_messages', 'id')->where('conversation_id', $conversation->id)]];
    }
}
