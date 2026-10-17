<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RemotesFrom;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class UpdateUserRemotePreferencesRequest extends FormRequest
{
    public function authorize(): Response
    {
        $user = $this->user();
        assert($user instanceof User);

        return $user->remotes_enabled ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', Rule::enum(RemotesFrom::class)],
            'pull_receipts' => ['required', 'boolean'],
        ];
    }

    public function remotesFrom(): RemotesFrom
    {
        return $this->enum('from', RemotesFrom::class) ?? RemotesFrom::Following;
    }
}
