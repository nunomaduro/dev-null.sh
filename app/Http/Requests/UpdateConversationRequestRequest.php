<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateConversationRequestRequest extends FormRequest
{
    public function authorize(): Response
    {
        $user = $this->user();
        $conversation = $this->route('conversation');
        assert($user instanceof User);
        assert($conversation instanceof Conversation);
        $other = User::query()->findOrFail($conversation->otherId($user));

        return $conversation->includes($user) && ! $user->hasBlockWith($other)
            && $conversation->initiator_id !== $user->id && $conversation->status !== 'accepted'
            && ($this->input('status') === 'declined' || ($user->dm_policy !== 'off' && $other->dm_policy !== 'off'))
            ? Response::allow() : Response::denyAsNotFound();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['status' => ['required', 'in:accepted,declined']];
    }
}
