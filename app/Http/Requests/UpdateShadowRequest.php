<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateShadowRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:32'],
            'glyph' => ['nullable', 'string', 'max:16'],
            'skin' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function shadowName(): ?string
    {
        $name = $this->string('name')->trim()->value();

        return $name !== '' ? $name : null;
    }

    public function glyph(): ?string
    {
        $glyph = $this->string('glyph')->trim()->value();

        return $glyph !== '' ? $glyph : null;
    }

    public function skin(): ?string
    {
        $skin = $this->string('skin')->trim()->value();

        return $skin !== '' ? $skin : null;
    }
}
