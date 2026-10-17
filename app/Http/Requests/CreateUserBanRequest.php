<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class CreateUserBanRequest extends FormRequest
{
    /**
     * Moderators may ban anyone but another moderator, or themselves.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        assert($user instanceof User);

        $banned = $this->route('user');
        assert($banned instanceof User);

        return $user->isAdmin() && ! $banned->isAdmin();
    }

    /**
     * @return array{}
     */
    public function rules(): array
    {
        return [];
    }
}
