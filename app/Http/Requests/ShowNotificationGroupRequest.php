<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;

final class ShowNotificationGroupRequest extends FormRequest
{
    public function authorize(): Response
    {
        $user = $this->user();
        assert($user instanceof User);
        $notification = $this->route('notification');
        assert($notification instanceof Notification);

        return $user->notifications()->relevant()->whereKey($notification->id)->exists()
            ? Response::allow() : Response::denyAsNotFound();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['type' => ['nullable', 'in:all,replies,quotes,stars,mentions,follows,moderation,messages']];
    }

    public function type(): string
    {
        return $this->filled('type') ? $this->string('type')->value() : 'all';
    }
}
