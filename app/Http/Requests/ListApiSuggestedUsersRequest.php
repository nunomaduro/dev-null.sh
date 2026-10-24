<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Commit;
use App\Models\User;
use App\Rules\VisibleCommit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Who to follow may be asked for the page it shows on, as the web asks for it beside a profile or a commit. The commit
 * has to be one the user can see, as its page requires.
 */
final class ListApiSuggestedUsersRequest extends FormRequest
{
    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        $user = $this->user();
        assert($user instanceof User);

        return [
            'round' => ['nullable', 'integer', 'min:0'],
            'username' => ['nullable', 'string', 'exists:users,username'],
            'commit_id' => ['bail', 'nullable', 'uuid', 'prohibits:username', new VisibleCommit($user, __('That commit is no longer available.'))],
        ];
    }

    public function context(): User|Commit|null
    {
        $user = $this->user();
        assert($user instanceof User);

        return match (true) {
            $this->filled('username') => User::query()->where('username', $this->string('username')->value())->firstOrFail(),
            $this->filled('commit_id') => Commit::query()->visibleTo($user)->findOrFail($this->string('commit_id')->value()),
            default => null,
        };
    }
}
