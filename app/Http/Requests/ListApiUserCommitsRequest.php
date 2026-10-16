<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class ListApiUserCommitsRequest extends FormRequest
{
    /**
     * Anyone may list the commits of a profile, but the web shows neither of two people across a block the other's
     * commits, so a signed request across one gets a code instead.
     */
    public function authorize(): bool
    {
        $user = $this->route('user');
        assert($user instanceof User);

        $viewer = $this->user();
        $blockerId = $viewer instanceof User ? $viewer->blockerIdWith($user) : null;

        throw_if($blockerId !== null, fn (): ApiException => ApiException::acrossBlock($user, blockedByViewer: $blockerId === $viewer?->id));

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'replies' => ['nullable', 'boolean'],
        ];
    }

    public function replies(): bool
    {
        return $this->boolean('replies');
    }
}
