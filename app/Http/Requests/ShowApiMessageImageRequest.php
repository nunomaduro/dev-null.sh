<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Message;
use App\Models\MessageImage;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ShowApiMessageImageRequest extends FormRequest
{
    /**
     * The URL was signed for one person in the conversation, so it stops working as soon as they can no longer see
     * the message, as the web's image does for them, as well as when it expires.
     */
    public function authorize(): Response
    {
        $messageImage = $this->route('messageImage');
        assert($messageImage instanceof MessageImage);

        $userId = $this->query('user');
        $user = is_string($userId) && Str::isUuid($userId) ? User::query()->find($userId) : null;
        $message = Message::query()->find($messageImage->message_id);

        return $user instanceof User && ! $user->isBanned() && $message instanceof Message && $message->isVisibleTo($user) && Storage::disk()->exists($messageImage->path)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * @return array{}
     */
    public function rules(): array
    {
        return [];
    }
}
