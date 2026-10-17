<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListNotificationSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'in:all,replies,quotes,stars,mentions,follows,moderation'],
            'visible' => ['sometimes', 'array', 'list', 'max:20'],
            'visible.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    public function type(): string
    {
        return $this->filled('type') ? $this->string('type')->value() : 'all';
    }

    /** @return list<string> */
    public function visible(): array
    {
        /** @var list<string> $visible */
        $visible = $this->validated('visible', []);

        return $visible;
    }
}
