<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class ListNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'in:all,replies,quotes,stars,mentions,follows,moderation,messages'],
            'through' => ['nullable', 'date'],
        ];
    }

    public function type(): string
    {
        return $this->filled('type') ? $this->string('type')->value() : 'all';
    }

    public function through(): ?CarbonImmutable
    {
        return $this->date('through')?->toImmutable();
    }
}
