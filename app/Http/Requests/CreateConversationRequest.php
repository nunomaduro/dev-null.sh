<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class CreateConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['username' => ['nullable', 'string', 'max:100', 'exists:users,username']];
    }

    public function recipient(): ?User
    {
        return $this->filled('username') ? User::query()->where('username', $this->string('username')->value())->first() : null;
    }
}
