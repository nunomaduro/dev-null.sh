<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListConversationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['folder' => ['nullable', 'in:inbox,requests,archived'], 'search' => ['nullable', 'string', 'max:100']];
    }

    public function folder(): string
    {
        return $this->string('folder', 'inbox')->value();
    }

    public function search(): string
    {
        return $this->string('search')->trim()->value();
    }
}
