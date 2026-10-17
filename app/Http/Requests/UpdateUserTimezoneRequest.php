<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateUserTimezoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'timezone' => ['present', 'nullable', 'string', 'timezone:all'],
        ];
    }

    public function timezone(): ?string
    {
        return $this->filled('timezone') ? $this->string('timezone')->value() : null;
    }
}
