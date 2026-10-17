<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class CreateApiUserFollowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $follower = $this->user();
        assert($follower instanceof User);

        $user = $this->route('user');
        assert($user instanceof User);

        return $follower->isNot($user) && ! $follower->hasBlockWith($user);
    }

    /**
     * @return array{}
     */
    public function rules(): array
    {
        return [];
    }
}
