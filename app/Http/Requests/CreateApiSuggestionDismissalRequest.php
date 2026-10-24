<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class CreateApiSuggestionDismissalRequest extends FormRequest
{
    /**
     * Anyone but themselves, as on the web.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        assert($user instanceof User);

        $dismissed = $this->route('user');
        assert($dismissed instanceof User);

        return $user->isNot($dismissed);
    }

    /**
     * @return array{}
     */
    public function rules(): array
    {
        return [];
    }
}
