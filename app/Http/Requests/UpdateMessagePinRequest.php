<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateMessagePinRequest extends FormRequest
{
    /**
     * Maintainers pin an org's messages for everyone in it, up to its limit, and only within the history they can see.
     * Events can't be pinned.
     */
    public function authorize(): Response
    {
        $user = $this->user();
        assert($user instanceof User);

        $message = $this->route('message');
        assert($message instanceof Message);

        $org = $message->conversation;

        return match (true) {
            ! $user->remotes_enabled || $message->event !== null || ! $message->isVisibleTo($user) || ! $org->isOrg() => Response::denyAsNotFound(),
            ! $org->isMaintainedBy($user) => Response::deny('Only maintainers can pin messages.'),
            $message->pinned_at === null && $org->messages()->whereNotNull('pinned_at')->count() >= Conversation::MAX_PINS => Response::deny('An org can pin up to '.Conversation::MAX_PINS.' messages. Unpin one first.'),
            default => Response::allow(),
        };
    }

    /**
     * @return array{}
     */
    public function rules(): array
    {
        return [];
    }
}
